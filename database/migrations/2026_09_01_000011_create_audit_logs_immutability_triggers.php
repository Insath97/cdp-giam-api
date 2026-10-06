<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Prevent UPDATE on audit_logs at the database engine level
        DB::unprepared('
            CREATE TRIGGER prevent_audit_logs_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "AuditLog records are strictly immutable and cannot be updated at the database level.";
            END
        ');

        // 2. Prevent DELETE on audit_logs at the database engine level
        DB::unprepared('
            CREATE TRIGGER prevent_audit_logs_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "AuditLog records are tamper-evident and cannot be deleted at the database level.";
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_logs_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_logs_delete');
    }
};
