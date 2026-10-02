<?php

declare(strict_types=1);

use App\Support\Roles\GrantPropertiesManage;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        GrantPropertiesManage::grant();
    }

    public function down(): void
    {
        GrantPropertiesManage::revoke();
    }
};
