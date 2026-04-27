<?php
/**
 * Export Functionality Test Script
 * Validates that export services and controllers are properly configured
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ReportExportService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

echo "=== EXPORT FUNCTIONALITY TEST ===\n";

// Test 1: Check ReportExportService exists and is instantiable
echo "\n1. Testing ReportExportService instantiation... ";
try {
    $app = app();
    $exportService = new ReportExportService();
    echo "✓ PASS\n";
} catch (\Throwable $e) {
    echo "✗ FAIL: {$e->getMessage()}\n";
}

// Test 2: Check Pdf facade is available
echo "2. Testing Pdf facade availability... ";
try {
    $pdfClass = 'Barryvdh\DomPDF\Facade\Pdf';
    if (class_exists($pdfClass)) {
        echo "✓ PASS\n";
    } else {
        echo "✗ FAIL: Pdf facade not found\n";
    }
} catch (\Throwable $e) {
    echo "✗ FAIL: {$e->getMessage()}\n";
}

// Test 3: Check PhpSpreadsheet is available
echo "3. Testing PhpSpreadsheet availability... ";
try {
    $spreadsheetClass = 'PhpOffice\PhpSpreadsheet\Spreadsheet';
    if (class_exists($spreadsheetClass)) {
        echo "✓ PASS\n";
    } else {
        echo "✗ FAIL: PhpSpreadsheet not found\n";
    }
} catch (\Throwable $e) {
    echo "✗ FAIL: {$e->getMessage()}\n";
}

// Test 4: Check temp directory is writable
echo "4. Testing temp directory accessibility... ";
try {
    $tempDir = sys_get_temp_dir();
    $testFile = tempnam($tempDir, 'test_export_');
    if ($testFile && file_exists($testFile)) {
        unlink($testFile);
        echo "✓ PASS (Temp dir: $tempDir)\n";
    } else {
        echo "✗ FAIL: Cannot write to temp directory\n";
    }
} catch (\Throwable $e) {
    echo "✗ FAIL: {$e->getMessage()}\n";
}

// Test 5: Check memory limit
echo "5. Checking PHP memory limit... ";
$memoryLimit = ini_get('memory_limit');
echo "Current: $memoryLimit - ";
if (strpos($memoryLimit, '-1') === 0 || (int)$memoryLimit >= 512) {
    echo "✓ PASS (>= 512M or unlimited)\n";
} else {
    echo "⚠ WARNING: May be insufficient for large exports\n";
}

// Test 6: Check max execution time
echo "6. Checking PHP max_execution_time... ";
$maxTime = ini_get('max_execution_time');
echo "Current: {$maxTime}s - ";
if ($maxTime == 0 || (int)$maxTime >= 300) {
    echo "✓ PASS (>= 300s or unlimited)\n";
} else {
    echo "⚠ WARNING: May timeout during large exports\n";
}

// Test 7: Check POST_MAX_SIZE and UPLOAD_MAX_SIZE
echo "7. Checking file size limits... ";
$postMax = ini_get('post_max_size');
$uploadMax = ini_get('upload_max_filesize');
echo "POST_MAX: $postMax, UPLOAD_MAX: $uploadMax\n";

// Test 8: Check controllers exist and have export methods
echo "8. Testing export methods existence...\n";
$controllers = [
    'App\Http\Controllers\Admin\ProductController' => ['exportExcel', 'exportPdf'],
    'App\Http\Controllers\Admin\PurchaseInvoiceController' => ['export'],
    'App\Http\Controllers\Admin\DistOrderController' => ['export'],
    'App\Http\Controllers\SalesInvoiceController' => ['export'],
];

foreach ($controllers as $controller => $methods) {
    $shortName = explode('\\', $controller);
    $shortName = end($shortName);
    echo "   - $shortName: ";
    
    if (class_exists($controller)) {
        $hasAllMethods = true;
        foreach ($methods as $method) {
            if (!method_exists($controller, $method)) {
                $hasAllMethods = false;
                break;
            }
        }
        echo $hasAllMethods ? "✓ PASS" : "✗ FAIL (missing methods: " . implode(', ', $methods) . ")";
    } else {
        echo "✗ FAIL (class not found)";
    }
    echo "\n";
}

// Test 9: Check database connection for export queries
echo "9. Testing database connection... ";
try {
    $app = app();
    $pdo = DB::connection()->getPdo();
    if ($pdo) {
        echo "✓ PASS (" . DB::connection()->getDatabaseName() . ")\n";
    } else {
        echo "✗ FAIL: No PDO connection\n";
    }
} catch (\Exception $e) {
    echo "⚠ WARNING: {$e->getMessage()}\n";
}

// Test 10: Test simple Excel export generation
echo "10. Testing Excel export generation... ";
try {
    $headers = ['Name', 'Email', 'Amount'];
    $rows = [
        ['John Doe', 'john@example.com', 100.00],
        ['Jane Smith', 'jane@example.com', 200.50],
    ];
    
    $exportService = new ReportExportService();
    $response = $exportService->downloadExcel('test_export_excel', 'Test Export', $headers, $rows, ['Rows' => count($rows)]);
    echo "✓ PASS (" . get_class($response) . ")\n";
} catch (\Exception $e) {
    echo "✗ FAIL: {$e->getMessage()}\n";
}

// Test 11: Test simple PDF export generation
echo "11. Testing PDF export generation... ";
try {
    $headers = ['Name', 'Email', 'Amount'];
    $rows = [
        ['John Doe', 'john@example.com', 100.00],
        ['Jane Smith', 'jane@example.com', 200.50],
    ];

    $exportService = new ReportExportService();
    $response = $exportService->downloadPdf('test_export_pdf', 'Test Export', $headers, $rows, ['Rows' => count($rows)]);
    echo "✓ PASS (" . get_class($response) . ")\n";
} catch (\Exception $e) {
    echo "✗ FAIL: {$e->getMessage()}\n";
}

// Test 12: Test a real controller export path without authorization gating
echo "12. Testing controller export route logic... ";
try {
    $request = \Illuminate\Http\Request::create('/admin/purchase-invoices/export', 'GET', [
        'status' => 'draft',
    ]);

    $response = app(\App\Http\Controllers\Admin\PurchaseInvoiceController::class)->export($request);
    echo "✓ PASS (" . get_class($response) . ")\n";
} catch (\Throwable $e) {
    echo "✗ FAIL: {$e->getMessage()}\n";
}

echo "\n=== TEST SUMMARY ===\n";
echo "All critical export components checked.\n";
echo "If any components failed, review the error messages above.\n";
echo "For Web UI testing, manually test each export endpoint.\n";
