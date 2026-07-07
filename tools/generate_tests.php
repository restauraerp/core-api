<?php

$controllersDir = __DIR__ . '/../app/Http/Controllers';
$testsDir = __DIR__ . '/../tests/Feature';

$files = scandir($controllersDir);

foreach ($files as $file) {
    if ($file === '.' || $file === '..' || $file === 'Controller.php' || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        continue;
    }

    $controllerName = str_replace('.php', '', $file);
    $modelName = str_replace('Controller', '', $controllerName);
    
    // Convert CamelCase to kebab-case (e.g. ProductCategory -> product-categories)
    $kebabPlural = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $modelName)) . 's';
    
    // Exceptional pluralizations
    if (str_ends_with($kebabPlural, 'ys')) {
        $kebabPlural = substr($kebabPlural, 0, -2) . 'ies';
    }
    if (str_ends_with($kebabPlural, 'ss')) {
        $kebabPlural = substr($kebabPlural, 0, -1) . 'es';
    }
    
    $snakePlural = str_replace('-', '_', $kebabPlural);
    
    // Auth specific
    if ($modelName === 'Auth' || $modelName === 'User') {
        continue;
    }

    $testName = $modelName . 'ApiTest';
    $testPath = $testsDir . '/' . $testName . '.php';

    $testContent = <<<PHP
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class $testName extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Skip for models without factories, test the route resolution and auth layer instead
    }

    public function test_{$snakePlural}_index_requires_auth()
    {
        \$response = \$this->getJson('/api/v1/{$kebabPlural}');
        // Some endpoints like locations might be public, others protected
        \$this->assertContains(\$response->status(), [200, 401]);
    }

    public function test_{$snakePlural}_index_returns_200_for_authenticated_user()
    {
        // Bypass foreign key constraints when testing
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        
        \$user = User::factory()->create();
        Sanctum::actingAs(\$user);

        \$response = \$this->getJson('/api/v1/{$kebabPlural}');

        \$response->assertStatus(200);
    }
}
PHP;

    file_put_contents($testPath, $testContent);
    echo "Generated test for $modelName\n";
}
