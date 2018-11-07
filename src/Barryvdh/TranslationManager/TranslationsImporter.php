<?php namespace Barryvdh\TranslationManager;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Events\Dispatcher;
use Barryvdh\TranslationManager\Models\Translation;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

class TranslationsImporter
{
	/** @var \Illuminate\Foundation\Application  */
    protected $app;
    /** @var \Illuminate\Filesystem\Filesystem  */
    protected $files;
    /** @var \Illuminate\Events\Dispatcher  */
    protected $events;

    protected $config;

    public function __construct(Application $app, Filesystem $files, Dispatcher $events)
    {
        $this->app = $app;
        $this->files = $files;
        $this->events = $events;
        $this->config = $app['config']['laravel-translation-manager::config'];
    }

	private function getLangDirectories()
	{
		$appPath = $this->app->make('path');
		return $this->files->directories($appPath.'/lang');
	}

	private function shouldExcludeGroup($groupName)
	{
		in_array($groupName, $this->config['exclude_groups']);
	}

	private function getLocalGroupTranslations($locale, $groupName)
	{
		$loader = \Lang::getLoader();
		return array_dot($loader->load($locale, $groupName));
	}

	private function getDatabaseGroupTranslationsMap($locale, $groupName)
	{
		$translations = Translation::where('group', '=', $groupName)->where('locale', $locale)->get();
		$map = [];
		foreach ($translations as $t) {
			$map[$t->key] = $t;
		}
		return $map;
	}

	private function generateInserts($data)
	{
		$sql = 'INSERT INTO ltm_translations (status, locale, group, key, value) VALUES ';
		$values = [];

		foreach ($data as $entry) {
			$values[] = "({$entry['status']}, '{$entry['locale']}', '{$entry['group']}', '{$entry['key']}', '{$entry['value']}')";
		}

		$sql .= implode(",\n", $values) . ";\n";
		return $sql;
	}

	private function generateUpdates($data)
	{
		$sql = '';

		foreach ($data as $entry) {
			$values = [];
			$thisSql = 'UPDATE ltm_translations SET ';

			if (array_key_exists('status', $entry)) {
				$values[] = "status = {$entry['status']}";
			}
			if (array_key_exists('value', $entry)) {
				$values[] = "value = '{$entry['value']}'";
			}

			$sql .= $thisSql . implode(", ", $values) . " WHERE id = {$entry['id']};\n";
		}

		return $sql;
	}

	private function processGroup($locale, $groupName, $replace = false)
	{
		$groupUpdates = [];
		$groupInserts = [];

		$localGroupTranslations = $this->getLocalGroupTranslations($locale, $groupName);
        $databaseGroupTranslationsMap = $this->getDatabaseGroupTranslationsMap($locale, $groupName);

        foreach ($localGroupTranslations as $key => $value) {
            $value = (string) $value;

            if (array_key_exists($key, $databaseGroupTranslationsMap)) {
            	$dbTranslation = $databaseGroupTranslationsMap[$key];
            	$newStatus = null;
				$updateValues = ['id' => $dbTranslation->id];

            	if ($value === $dbTranslation->value) {
            		$newStatus = Translation::STATUS_CHANGED;
            	}
            	else {
            		$newStatus = Translation::STATUS_SAVED;
            	}

            	if ($newStatus !== (int) $dbTranslation->status) {
            		$updateValues['status'] = $newStatus;
            	}

            	// Only replace when empty, or explicitly told so
	            if ($replace || !$dbTranslation->value && $value !== $dbTranslation->value) {
	                $updateValues['value'] = $value;
            	}

            	if (array_key_exists('value', $updateValues) || array_key_exists('status', $updateValues)) {
            		$groupUpdates[] = $updateValues;
            	}
            }
            else {
            	// New
            	$groupInserts[] = [
            		'status' => Translation::STATUS_CHANGED,
            		'locale' => $locale,
            		'group' => $groupName,
            		'key' => $key,
            		'value' => $value,
            	];
            }
        }


		$sql = '';
        if (!empty($groupInserts)) {
        	$sql .= $this->generateInserts($groupInserts);
        }

        if (!empty($groupUpdates)) {
        	$sql .= $this->generateUpdates($groupUpdates);
        }

        echo "${sql}";

        return count($groupUpdates) + count($groupInserts);
	}


	public function import($replace = false)
    {
        $updateCount = 0;

        foreach($this->getLangDirectories() as $langPath) {
            $locale = basename($langPath);

            foreach ($this->files->files($langPath) as $file) {
                $info = pathinfo($file);
                $groupName = $info['filename'];

				if ($this->shouldExcludeGroup($groupName)) {
		            continue;
		        }

                $groupUpdateCount = $this->processGroup($locale, $groupName, $replace);
                $updateCount += $groupUpdateCount;
            }
        }

        return $updateCount;
    }
}