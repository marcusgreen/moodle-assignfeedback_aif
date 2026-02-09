<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace assignfeedback_aif\task;

/**
 * Scheduled task to clean up expired file content cache entries.
 *
 * Entries in the assignfeedback_aif_rescache table that have not been accessed
 * within the configured delay period are deleted to save storage.
 *
 * @package    assignfeedback_aif
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_cache extends \core\task\scheduled_task {
    /**
     * Get the name of the task.
     *
     * @return string The task name.
     */
    public function get_name(): string {
        return get_string('taskcleanupcache', 'assignfeedback_aif');
    }

    /**
     * Execute the task.
     *
     * Deletes cache entries that have not been accessed within the configured delay.
     */
    public function execute(): void {
        global $DB;

        $delaydays = (int) get_config('assignfeedback_aif', 'cachecleanupdelay');
        if ($delaydays <= 0) {
            mtrace("Cache cleanup disabled (delay is 0).");
            return;
        }

        $clock = \core\di::get(\core\clock::class);
        $threshold = $clock->now()->getTimestamp() - ($delaydays * DAYSECS);

        $count = $DB->count_records_select('assignfeedback_aif_rescache', 'timelastaccessed < :threshold', [
            'threshold' => $threshold,
        ]);

        if ($count === 0) {
            mtrace("No expired cache entries found.");
            return;
        }

        $DB->delete_records_select('assignfeedback_aif_rescache', 'timelastaccessed < :threshold', [
            'threshold' => $threshold,
        ]);

        mtrace("Deleted {$count} expired cache entries.");
    }
}
