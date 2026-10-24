<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('firstName');
            $table->string('lastName');
            $table->string('contact');
            $table->string('branch_code');
            $table->string('branch');
            $table->string('position');
            $table->string('userName')->unique();
            $table->string('employee_id')->unique();
            $table->longText('signature')->nullable();
            $table->string('role')->default('user');
            $table->string('profile_picture')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['userName']);
            $table->dropUnique(['employee_id']);

            $table->dropColumn([
                'firstName',
                'lastName',
                'contact',
                'branch_code',
                'branch',
                'position',
                'userName',
                'employee_id',
                'signature',
                'role',
                'profile_picture'
            ]);
        });
    }
};
