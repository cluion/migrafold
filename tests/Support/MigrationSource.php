<?php

declare(strict_types=1);

namespace Tests\Support;

final readonly class MigrationSource
{
    public static function users(bool $uniqueEmail = true): string
    {
        $email = $uniqueEmail
            ? "            \$table->string('email')->unique();"
            : "            \$table->string('email');";

        return <<<PHP
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint \$table): void {
            \$table->id();
{$email}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
PHP;
    }

    public static function usersWithIdOnly(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
PHP;
    }

    public static function invoices(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', static function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
PHP;
    }

    public static function insertUser(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->insert(['email' => 'preserved@example.test']);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'preserved@example.test')->delete();
    }
};
PHP;
    }
}
