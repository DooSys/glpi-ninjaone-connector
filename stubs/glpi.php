<?php

/**
 * Minimal GLPI stubs for IDE/static analysis.
 *
 * GLPI loads these classes at runtime from its own application tree. They are
 * declared here only so language servers can understand the plugin workspace
 * when the full GLPI source is not opened next to it.
 */

namespace {

if (!class_exists('CommonGLPI')) {
    class CommonGLPI
    {
        public function getID(): int
        {
            return 0;
        }

        public function getType(): string
        {
            return static::class;
        }
    }
}

if (!class_exists('CommonDBTM')) {
    class CommonDBTM extends CommonGLPI
    {
        public array $fields = [];
        public static $rightname = '';

        public static function getFormURLWithID(int $id): string
        {
            return '';
        }

        public static function getSearchURL(bool $full = true): string
        {
            return '';
        }

        public static function getTable($classname = null): string
        {
            return '';
        }

        public static function createTabEntry(string $title, int $count = 0, $icon = null, string $icon_class = ''): string
        {
            return '';
        }

        public function add(array $input): int
        {
            return 0;
        }

        public function getField(string $field)
        {
            return $this->fields[$field] ?? null;
        }

        public function getFromDB(int $id): bool
        {
            return false;
        }

        public function maybeDeleted(): bool
        {
            return false;
        }

        public function maybeTemplate(): bool
        {
            return false;
        }

        public function update(array $input): bool
        {
            return false;
        }
    }
}

if (!class_exists('Computer')) {
    class Computer extends CommonDBTM
    {
    }
}

if (!class_exists('CronTask')) {
    class CronTask extends CommonDBTM
    {
        public const MODE_EXTERNAL = 2;
        public const MODE_INTERNAL = 1;
        public const STATE_WAITING = 1;

        public static function Register(string $itemtype, string $name, int $frequency, array $options = []): void
        {
        }

        public static function Unregister(string $itemtype): void
        {
        }
    }
}

if (!class_exists('Dropdown')) {
    class Dropdown
    {
        public static function showYesNo(string $name, int $value = 0, int $restrict = -1, array $params = []): void
        {
        }
    }
}

if (!class_exists('Html')) {
    class Html
    {
        public static function back(): void
        {
        }

        public static function closeForm(): void
        {
        }

        public static function footer(): void
        {
        }

        public static function header(string $title, string $url = '', string $sector = 'none', string $item = '', string $option = ''): void
        {
        }

        public static function hidden(string $name, array $options = []): string
        {
            return '';
        }

        public static function redirect(string $url): void
        {
        }

        public static function submit(string $name, array $options = []): string
        {
            return '';
        }
    }
}

if (!class_exists('Location')) {
    class Location extends CommonDBTM
    {
    }
}

if (!class_exists('Plugin')) {
    class Plugin
    {
        public static function loadLang(string $plugin): void
        {
        }

        public static function registerClass(string $classname, array $options = []): void
        {
        }
    }
}

if (!class_exists('Search')) {
    class Search
    {
        public static function show(string $itemtype): void
        {
        }
    }
}

if (!class_exists('Session')) {
    class Session
    {
        public static function addMessageAfterRedirect(string $message, bool $check_once = true, int $type = 0): void
        {
        }

        public static function checkRight(string $right, int $value): void
        {
        }

        public static function getNewCSRFToken(): string
        {
            return '';
        }

        public static function haveRight(string $right, int $value): bool
        {
            return false;
        }
    }
}

if (!class_exists('Item_SoftwareVersion')) {
    class Item_SoftwareVersion extends CommonDBTM
    {
    }
}

if (!class_exists('Software')) {
    class Software extends CommonDBTM
    {
    }
}

if (!class_exists('SoftwareVersion')) {
    class SoftwareVersion extends CommonDBTM
    {
    }
}

if (!class_exists('Manufacturer')) {
    class Manufacturer extends CommonDBTM
    {
    }
}

if (!class_exists('ComputerModel')) {
    class ComputerModel extends CommonDBTM
    {
    }
}

if (!class_exists('ComputerType')) {
    class ComputerType extends CommonDBTM
    {
    }
}

if (!class_exists('OperatingSystem')) {
    class OperatingSystem extends CommonDBTM
    {
    }
}

if (!class_exists('OperatingSystemVersion')) {
    class OperatingSystemVersion extends CommonDBTM
    {
    }
}

if (!class_exists('OperatingSystemArchitecture')) {
    class OperatingSystemArchitecture extends CommonDBTM
    {
    }
}

if (!class_exists('OperatingSystemServicePack')) {
    class OperatingSystemServicePack extends CommonDBTM
    {
    }
}

if (!class_exists('OperatingSystemKernel')) {
    class OperatingSystemKernel extends CommonDBTM
    {
    }
}

if (!class_exists('OperatingSystemEdition')) {
    class OperatingSystemEdition extends CommonDBTM
    {
    }
}

}

namespace Glpi\Plugin {

    if (!class_exists(Hooks::class)) {
        class Hooks
        {
            public const CONFIG_PAGE = 'config_page';
            public const MENU_TOADD = 'menu_toadd';
        }
    }
}
