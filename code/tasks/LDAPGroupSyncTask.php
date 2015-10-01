<?php
/**
 * Class LDAPGroupSyncTask
 *
 * A task to sync all groups to the site using LDAP.
 */
class LDAPGroupSyncTask extends BuildTask {

	private static $dependencies = array(
		'ldapService' => '%$LDAPService'
	);

	/**
	 * Setting this to true causes the sync to delete any local Group
	 * records that were previously imported, but no longer existing in LDAP.
	 *
	 * @config
	 * @var bool
	 */
	private static $destructive = false;

	public function getTitle() {
		return _t('LDAPGroupSyncJob.SYNCTITLE', 'Sync all groups from Active Directory');
	}

	public function run($request) {
		// get all groups from LDAP, but only get the attributes we need.
		// this is useful to avoid holding onto too much data in memory
		// especially in the case where getGroups() would return a lot of groups
		$ldapGroups = $this->ldapService->getGroups(
			false,
			array('objectguid', 'samaccountname', 'dn', 'name', 'description'),
			// Change the indexing attribute so we can look up by GUID during the deletion process below.
			'objectguid'
		);

		$start = time();

		$count = 0;

		foreach($ldapGroups as $data) {
			$group = Group::get()->filter('GUID', $data['objectguid'])->limit(1)->first();

			if(!($group && $group->exists())) {
				// create the initial Group with some internal fields
				$group = new Group();
				$group->GUID = $data['objectguid'];

				$this->log(sprintf(
					'Creating new Group (ID: %s, GUID: %s, sAMAccountName: %s)',
					$group->ID,
					$data['objectguid'],
					$data['samaccountname']
				));
			} else {
				$this->log(sprintf(
					'Updating existing Group "%s" (ID: %s, GUID: %s, sAMAccountName: %s)',
					$group->getTitle(),
					$group->ID,
					$data['objectguid'],
					$data['samaccountname']
				));
			}

			$this->syncGroup($group, $data, true);

			// cleanup object from memory
			$group->destroy();

			$count++;
		}

		// remove Group records that were previously imported, but no longer exist in the directory
		// NOTE: DB::query() here is used for performance and so we don't run out of memory
		if($this->config()->destructive) {
			foreach(DB::query('SELECT "ID", "GUID" FROM "Group" WHERE "IsImportedFromLDAP" = 1') as $record) {
				if(!isset($ldapGroups[$record['GUID']])) {
					$group = Group::get()->byId($record['ID']);
					// Cascade into mappings, just to clean up behind ourselves.
					foreach ($group->LDAPGroupMappings() as $mapping) {
						$mapping->delete();
					}
					$group->delete();

					$this->log(sprintf(
						'Removing Group "%s" (GUID: %s) that no longer exists in LDAP.',
						$group->Title,
						$group->GUID
					));

					// cleanup object from memory
					$group->destroy();
				}
			}
		}

		$end = time() - $start;

		$this->log(sprintf('Done. Processed %s records. Duration: %s seconds', $count, round($end, 0)));
	}

	/**
	 * Sync a specific Group by updating it with LDAP data.
	 *
	 * @param Group $group An existing Group or a new Group object
	 * @param array $data LDAP group object data
	 * @param bool $log Should information on actions performed be logged?
	 */
	public function syncGroup(Group $group, $data, $log = false) {
		// Synchronise specific guaranteed fields.
		$group->Code = $data['samaccountname'];
		if (!empty($data['name'])) {
			$group->Title = $data['name'];
		} else {
			$group->Title = $data['samaccountname'];
		}
		if (!empty($data['description'])) $group->Description = $data['description'];
		$group->DN = $data['dn'];
		$group->LastSynced = (string)SS_Datetime::now();
		$group->IsImportedFromLDAP = true;
		$group->write();

		// Mappings on this group are automatically maintained to contain just the group's DN.
		// First, scan through existing mappings and remove ones that are not matching (in case the group moved).
		$hasCorrectMapping = false;
		foreach ($group->LDAPGroupMappings() as $mapping) {
			if ($mapping->DN === $data['dn']) {
				// This is the correct mapping we want to retain.
				$hasCorrectMapping = true;
			} else {
				if($log) {
					$this->log(sprintf(
						'Deleting invalid mapping %s on %s.',
						$mapping->DN,
						$group->getTitle()
					));
				}

				$mapping->delete();
			}
		}

		// Second, if the main mapping was not found, add it in.
		if (!$hasCorrectMapping) {
			if($log) {
				$this->log(sprintf(
					'Setting up missing group mapping from %s to %s',
					$group->getTitle(),
					$data['dn']
				));
			}

			$mapping = new LDAPGroupMapping();
			$mapping->DN = $data['dn'];
			$mapping->write();
			$group->LDAPGroupMappings()->add($mapping);
		}
	}

	protected function log($message) {
		$message = sprintf('[%s] ', date('Y-m-d H:i:s')) . $message;
		echo Director::is_cli() ? ($message . PHP_EOL) : ($message . '<br>');
	}

}

