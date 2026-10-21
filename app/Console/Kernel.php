<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('trabajonautas:send-unnotified-clients')->dailyAt('20:30'); // Send notification and emails to clients
        $schedule->command('trabajonautas:delete-incomplete-clients')->dailyAt('20:00'); // Delete clients register_completed = false
        $schedule->command('trabajonautas:update-expired-accounts')->hourly(); // Convert to FREE when user is expired account
        // 1. Send email in queue when: Client is register completed and User/Admin verified account (PRO of PRO-MAX)
        // 2. Send Notifications to all users PRO-MAX if location and profesion is same at announcement
        $schedule->command('queue:restart')->everyFiveMinutes();
        $schedule->command('queue:prune-failed --hours=168')->weekly(); // Clear failed_jobs after 3 trying times 
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
