<?php

namespace App\Commands;

use App\Libraries\Installer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\MigrationRunner;
use Config\Florist;

/**
 * php spark florist:install [--no-seed]
 *
 * Same as the dashboard's Install screen, for servers with SSH.
 */
class Install extends BaseCommand
{
    protected $group       = 'City Florist';
    protected $name        = 'florist:install';
    protected $description = 'Create the tables, the admin login, the categories and the starter products';
    protected $usage       = 'florist:install [options]';
    protected $options     = [
        '--no-seed' => 'Skip importing the starter product photos',
    ];

    public function run(array $params): int
    {
        if (Installer::installed()) {
            CLI::write('Already installed. Running any new migrations instead.', 'green');
            $this->migrate();

            return EXIT_SUCCESS;
        }
        foreach (Installer::run(! self::option($params, 'no-seed')) as $line) {
            CLI::write("✓ {$line}");
        }
        CLI::write('Log in at /admin with username "' . config(Florist::class)->adminUsername . '" and the password from .env, then change it in Settings.', 'green');

        return EXIT_SUCCESS;
    }

    /** Installer::migrate(), saying which migrations it ran (Laravel called its own `migrate` command here). */
    private function migrate(): void
    {
        /** @var MigrationRunner $runner */
        $runner = service('migrations', null, null, false);
        $runner->setNamespace('App');
        // What MigrationRunner::latest() is about to run: every migration file not in the history yet.
        $pending = $runner->findMigrations();
        foreach ($runner->getHistory('') as $row) {
            unset($pending[$runner->getObjectUid($row)]);
        }

        Installer::migrate();

        if ($pending === []) {
            CLI::write('Nothing to migrate.');

            return;
        }
        CLI::write('Running migrations.');
        foreach ($pending as $migration) {
            CLI::write("  {$migration->version}_{$migration->name} DONE");
        }
    }

    /** True when the flag was given (`php spark` passes options in $params; CLI::getOption covers other callers). */
    private static function option(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null;
    }
}
