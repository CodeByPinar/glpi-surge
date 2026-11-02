<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * Install / upgrade only ADD things (missing tables, settings, rights, the automatic action); existing rules, surges,
 * settings and rights are never changed. The default rule is created on a fresh install only.
 */

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Surge;

function plugin_surge_install(): bool
{
    /** @var \DBmysql $DB */
    global $DB;

    $cs = DBConnection::getDefaultCharset();
    $co = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $fresh = !$DB->tableExists(Rule::TABLE);

    $tables = [
        Rule::TABLE => "(
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `entities_id` INT {$sign} NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 1,
            `window_minutes` INT NOT NULL DEFAULT 30,
            `min_tickets` INT NOT NULL DEFAULT 5,
            `similarity` DECIMAL(4,2) NOT NULL DEFAULT 0.35,
            `match_category` TINYINT NOT NULL DEFAULT 1,
            `match_location` TINYINT NOT NULL DEFAULT 0,
            `match_title` TINYINT NOT NULL DEFAULT 1,
            `ticket_type` TINYINT NOT NULL DEFAULT 0,
            `categories` TEXT NULL,
            `comment` TEXT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `is_active` (`is_active`)
        )",
        Surge::TABLE => "(
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `rules_id` INT {$sign} NOT NULL DEFAULT 0,
            `entities_id` INT {$sign} NOT NULL DEFAULT 0,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `status` VARCHAR(20) NOT NULL DEFAULT 'new',
            `itilcategories_id` INT {$sign} NOT NULL DEFAULT 0,
            `locations_id` INT {$sign} NOT NULL DEFAULT 0,
            `keywords` TEXT NULL,
            `ticket_count` INT NOT NULL DEFAULT 0,
            `date_first` TIMESTAMP NULL DEFAULT NULL,
            `date_last` TIMESTAMP NULL DEFAULT NULL,
            `tickets_id_master` INT {$sign} NOT NULL DEFAULT 0,
            `problems_id` INT {$sign} NOT NULL DEFAULT 0,
            `users_id_ack` INT {$sign} NOT NULL DEFAULT 0,
            `date_ack` TIMESTAMP NULL DEFAULT NULL,
            `date_resolved` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `status` (`status`), KEY `rules_id` (`rules_id`), KEY `tickets_id_master` (`tickets_id_master`),
            KEY `entities_id` (`entities_id`), KEY `date_last` (`date_last`)
        )",
        Surge::ITEMS => "(
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `surges_id` INT {$sign} NOT NULL DEFAULT 0,
            `tickets_id` INT {$sign} NOT NULL DEFAULT 0,
            `score` DECIMAL(5,3) NOT NULL DEFAULT 0,
            `is_removed` TINYINT NOT NULL DEFAULT 0,
            `date_add` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `unicity` (`surges_id`, `tickets_id`), KEY `tickets_id` (`tickets_id`)
        )",
    ];
    foreach ($tables as $name => $def) {
        if (!$DB->tableExists($name)) {
            $DB->doQuery("CREATE TABLE `{$name}` {$def} ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co} ROW_FORMAT=DYNAMIC");
        }
    }

    if ($fresh) {
        Rule::save(0, [
            'name'    => __('Same category, similar text', 'surge'),
            'comment' => __('Default rule: 5 or more open tickets in 30 minutes, same category, similar title or description.', 'surge'),
        ] + Rule::FIELDS);
    }
    Config::install();
    Profile::install();
    CronTask::register(Surge::class, 'surgeDetect', 5 * MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Surge',
    ]);
    return true;
}

function plugin_surge_uninstall(): bool
{
    /** @var \DBmysql $DB */
    global $DB;
    foreach ([Rule::TABLE, Surge::TABLE, Surge::ITEMS] as $t) {
        if ($DB->tableExists($t)) {
            $DB->doQuery("DROP TABLE `{$t}`");
        }
    }
    Config::uninstall();
    Profile::uninstall();
    CronTask::unregister('surge');
    return true;
}
