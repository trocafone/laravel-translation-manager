<?php namespace Barryvdh\TranslationManager\Console;

use Barryvdh\TranslationManager\Manager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class ImportCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'translations:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import translations from the PHP sources';

    /** @var  \Barryvdh\TranslationManager\Manager  */
    protected $manager;

    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
        parent::__construct();
    }


    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        try {
            $timeStart = microtime(true);

            $replace = $this->option('replace');
            $counter = $this->manager->importTranslations($replace);


            $timeEnd = microtime(true);
            $executionSecs = ($timeEnd - $timeStart)/60;
            $this->info('Done importing, processed '.$counter. ' items!');
            $this->info('The process took '.$executionSecs. ' seconds!');
        }
        catch (\Exception $ex) {
            echo $ex;
        }
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
            array('replace', "R", InputOption::VALUE_NONE, 'Replace existing keys'),
        );
    }


}
