<?php
defined('ABSPATH') || exit;

final class HBC_Laugh_Activator {
    public static function activate(): void {
        HBC_Laugh_Schema::install();
        HBC_Laugh_Readiness::close_registration();
        flush_rewrite_rules();
    }
}
