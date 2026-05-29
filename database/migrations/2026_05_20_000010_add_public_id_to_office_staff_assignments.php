<?php

declare(strict_types=1);

use App\Models\OfficeStaffAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_staff_assignments', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
        });

        OfficeStaffAssignment::query()
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(500, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    $assignment->forceFill(['public_id' => (string) Str::ulid()])->save();
                }
            });

        DB::statement('ALTER TABLE office_staff_assignments ALTER COLUMN public_id SET NOT NULL');

        Schema::table('office_staff_assignments', function (Blueprint $table): void {
            $table->unique('public_id');
            $table->dropUnique(['user_id', 'office_id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX office_staff_assignments_active_unique
                ON office_staff_assignments (user_id, office_id)
                WHERE removed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS office_staff_assignments_active_unique');

        Schema::table('office_staff_assignments', function (Blueprint $table): void {
            $table->unique(['user_id', 'office_id']);
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
