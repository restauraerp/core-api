<?php

$modelsDir = __DIR__ . '/../app/Models';
$controllersDir = __DIR__ . '/../app/Http/Controllers';

$files = scandir($modelsDir);

foreach ($files as $file) {
    if ($file === '.' || $file === '..' || $file === 'User.php' || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        continue;
    }

    $modelName = str_replace('.php', '', $file);
    $controllerName = $modelName . 'Controller';
    $controllerPath = $controllersDir . '/' . $controllerName . '.php';

    if (!file_exists($controllerPath)) {
        continue;
    }

    $controllerContent = file_get_contents($controllerPath);
    
    // Skip if it already has logic (e.g. store method has more than just a comment)
    if (strpos($controllerContent, '$request->validate(') !== false) {
        continue;
    }

    $modelContent = file_get_contents($modelsDir . '/' . $file);

    // Extract fillable
    preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\];/s', $modelContent, $matches);
    $fillable = [];
    if (isset($matches[1])) {
        preg_match_all("/'([^']+)'/", $matches[1], $fieldMatches);
        if (isset($fieldMatches[1])) {
            $fillable = $fieldMatches[1];
        }
    }

    $validationRules = [];
    foreach ($fillable as $field) {
        if (str_ends_with($field, '_id')) {
            $validationRules[] = "'$field' => 'nullable|integer',";
        } elseif (str_starts_with($field, 'is_') || str_starts_with($field, 'has_')) {
            $validationRules[] = "'$field' => 'boolean',";
        } else {
            $validationRules[] = "'$field' => 'nullable|string',";
        }
    }
    
    $validationRulesString = implode("\n            ", $validationRules);
    
    $camelModel = lcfirst($modelName);
    
    $newControllerContent = <<<PHP
<?php

namespace App\Http\Controllers;

use App\Models\\$modelName;
use Illuminate\Http\Request;

class $controllerName extends Controller
{
    public function index()
    {
        return response()->json($modelName::all());
    }

    public function store(Request \$request)
    {
        \$validated = \$request->validate([
            $validationRulesString
        ]);

        \$$camelModel = $modelName::create(\$validated);
        return response()->json(\$$camelModel, 201);
    }

    public function show($modelName \$$camelModel)
    {
        return response()->json(\$$camelModel);
    }

    public function update(Request \$request, $modelName \$$camelModel)
    {
        \$validated = \$request->validate([
            $validationRulesString
        ]);

        \${$camelModel}->update(\$validated);
        return response()->json(\$$camelModel);
    }

    public function destroy($modelName \$$camelModel)
    {
        \${$camelModel}->delete();
        return response()->json(null, 204);
    }
}
PHP;

    file_put_contents($controllerPath, $newControllerContent);
    echo "Generated CRUD for $controllerName\n";
}
