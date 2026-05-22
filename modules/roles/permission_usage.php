<?php

function permissionUsageSummary(array $permission): string {
    $description = trim((string)($permission['description'] ?? ''));
    if ($description !== '') {
        return $description;
    }

    $key = (string)($permission['key'] ?? '');
    $module = (string)($permission['module'] ?? 'general');
    $name = (string)($permission['name'] ?? $key);

    return 'Controls ' . $name . ' behavior in ' . $module . ' screens and actions.';
}

function permissionUsageLocations(string $permissionKey): array {
    static $usageIndex = null;

    if ($usageIndex === null) {
        $usageIndex = buildPermissionUsageIndex();
    }

    $locations = $usageIndex[$permissionKey] ?? [];

    if (strpos($permissionKey, 'manufacturing.view_step_') === 0) {
        $locations[] = [
            'path' => 'modules/manufacturing/order.php',
            'label' => 'Manufacturing > Order step visibility',
            'lines' => [1274, 1351],
        ];
    }

    if (strpos($permissionKey, 'region.') === 0) {
        $locations[] = [
            'path' => 'includes/auth.php',
            'label' => 'Login > Region access',
            'lines' => [42, 45],
        ];
    }

    return dedupePermissionUsageLocations($locations);
}

function buildPermissionUsageIndex(): array {
    $root = realpath(dirname(__DIR__, 2));
    if ($root === false) {
        return [];
    }

    $paths = [
        $root . '/ajax',
        $root . '/includes',
        $root . '/modules',
        $root . '/dashboard.php',
        $root . '/dashboard-test.php',
    ];

    $index = [];
    foreach ($paths as $path) {
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                addPermissionUsagesFromFile($index, $root, $file->getPathname());
            }
        } elseif (is_file($path)) {
            addPermissionUsagesFromFile($index, $root, $path);
        }
    }

    foreach ($index as $key => $locations) {
        usort($locations, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
        $index[$key] = $locations;
    }

    return $index;
}

function addPermissionUsagesFromFile(array &$index, string $root, string $filePath) {
    $contents = @file($filePath);
    if ($contents === false) {
        return;
    }

    $relativePath = ltrim(str_replace($root, '', $filePath), DIRECTORY_SEPARATOR);
    $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
    $foundLines = [];

    foreach ($contents as $lineNumber => $line) {
        if (!preg_match_all('/has(?:Explicit)?Permission\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)|hasRole\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $matches, PREG_SET_ORDER)) {
            $matches = [];
        }

        foreach ($matches as $match) {
            $key = !empty($match[1]) ? $match[1] : ($match[2] ?? '');
            if ($key === '') {
                continue;
            }
            $foundLines[$key][] = $lineNumber + 1;
        }

        if (preg_match_all('/[\'"]((?:analysis|categories|contacts|customers|finance|inventories|locations|manufacturing|notifications|products|purchases|quotations|region|regions|sales|tickets|users|vendors)\.[a-z0-9_.]+)[\'"]/', $line, $literalMatches)) {
            foreach ($literalMatches[1] as $key) {
                $foundLines[$key][] = $lineNumber + 1;
            }
        }
    }

    foreach ($foundLines as $key => $lines) {
        $index[$key][] = [
            'path' => $relativePath,
            'label' => permissionUsagePathLabel($relativePath),
            'lines' => array_values(array_unique($lines)),
        ];
    }
}

function permissionUsagePathLabel(string $path): string {
    $withoutExtension = preg_replace('/\.php$/', '', $path);

    if ($withoutExtension === 'dashboard') {
        return 'Dashboard';
    }

    if ($withoutExtension === 'dashboard-test') {
        return 'Dashboard test';
    }

    if (strpos($withoutExtension, 'includes/') === 0) {
        return 'Layout > ' . permissionUsageTitle(substr($withoutExtension, strlen('includes/')));
    }

    if (strpos($withoutExtension, 'ajax/') === 0) {
        return 'AJAX > ' . permissionUsageTitle(substr($withoutExtension, strlen('ajax/')));
    }

    if (strpos($withoutExtension, 'modules/') === 0) {
        $parts = explode('/', substr($withoutExtension, strlen('modules/')));
        $parts = array_map('permissionUsageTitle', $parts);
        return implode(' > ', $parts);
    }

    return permissionUsageTitle($withoutExtension);
}

function permissionUsageTitle(string $value): string {
    $value = str_replace(['_', '-'], ' ', $value);
    return ucwords($value);
}

function dedupePermissionUsageLocations(array $locations): array {
    $seen = [];
    $deduped = [];

    foreach ($locations as $location) {
        $key = $location['path'] . ':' . implode(',', $location['lines']);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $deduped[] = $location;
    }

    usort($deduped, function ($a, $b) {
        return strcmp($a['path'], $b['path']);
    });

    return $deduped;
}

function permissionUsageLinePreview(array $lines): string {
    $lines = array_values(array_unique(array_map('intval', $lines)));
    sort($lines);

    $visible = array_slice($lines, 0, 4);
    $preview = implode(', ', $visible);
    $remaining = count($lines) - count($visible);

    if ($remaining > 0) {
        $preview .= ', +' . $remaining;
    }

    return $preview;
}

function renderPermissionUsageStyles() {
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    ?>
    <style>
      .permission-grid {
        row-gap: 0.75rem;
      }

      .permission-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
      }

      .permission-filter {
        max-width: 320px;
      }

      .permission-module-card {
        height: 100%;
        background: #fcfcfd;
        border: 1px solid #e7eaee;
        border-radius: 8px;
        padding: 0.65rem;
      }

      .permission-module-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding-bottom: 0.45rem;
        margin-bottom: 0.5rem;
        border-bottom: 1px solid #eceff3;
      }

      .permission-module-title {
        font-size: 0.92rem;
        font-weight: 700;
        margin: 0;
      }

      .permission-module-count {
        font-size: 0.72rem;
        color: #6c757d;
        white-space: nowrap;
      }

      .permission-option {
        padding: 0.42rem 0.5rem 0.42rem 1.95rem;
        margin-bottom: 0.35rem;
        background: #fff;
        border: 1px solid #edf0f2;
        border-radius: 6px;
      }

      .permission-option:hover {
        border-color: #d7dce2;
        background: #fbfcfe;
      }

      .permission-option .form-check-input {
        margin-top: 0.42rem;
      }

      .permission-title-line {
        display: flex;
        align-items: baseline;
        gap: 0.4rem;
        flex-wrap: wrap;
        line-height: 1.2;
      }

      .permission-title-line code {
        font-size: 0.73rem;
        color: #0f5132;
        background: #eef8f1;
        border: 1px solid #d8ecde;
        border-radius: 4px;
        padding: 0.08rem 0.28rem;
      }

      .permission-name {
        font-size: 0.86rem;
        font-weight: 600;
        color: #212529;
      }

      .permission-usage-details {
        margin-top: 0.16rem;
        margin-left: 0 !important;
        font-size: 0.77rem;
        line-height: 1.3;
      }

      .permission-summary {
        color: #6c757d;
      }

      .permission-references {
        margin-top: 0.1rem;
      }

      .permission-references summary {
        width: fit-content;
        cursor: pointer;
        color: #495057;
        font-weight: 600;
        list-style: none;
      }

      .permission-references summary::-webkit-details-marker {
        display: none;
      }

      .permission-references summary::before {
        content: "+";
        display: inline-block;
        width: 0.85rem;
        color: #0d6efd;
        font-weight: 700;
      }

      .permission-references[open] summary::before {
        content: "-";
      }

      .permission-reference-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        margin-top: 0.3rem;
      }

      .permission-reference-chip {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        padding: 0.14rem 0.38rem;
        border: 1px solid #e1e5ea;
        border-radius: 999px;
        background: #f8f9fa;
        color: #343a40;
        font-size: 0.72rem;
      }

      .permission-reference-path {
        color: #6c757d;
        margin-left: 0.2rem;
      }
    </style>
    <?php
}

function renderPermissionUsageDetails(array $permission) {
    renderPermissionUsageStyles();

    $key = (string)($permission['key'] ?? '');
    $summary = permissionUsageSummary($permission);
    $locations = $key !== '' ? permissionUsageLocations($key) : [];
    $visibleLocations = array_slice($locations, 0, 8);
    $remainingLocations = count($locations) - count($visibleLocations);
    ?>
    <div class="form-text ms-4 permission-usage-details">
      <div class="permission-summary"><?= htmlspecialchars($summary) ?></div>
      <?php if (!empty($visibleLocations)): ?>
        <details class="permission-references">
          <summary><?= (int)count($locations) ?> reference<?= count($locations) === 1 ? '' : 's' ?></summary>
          <div class="permission-reference-list">
          <?php foreach ($visibleLocations as $location): ?>
            <span class="permission-reference-chip">
              <?= htmlspecialchars($location['label']) ?>
              <span class="permission-reference-path"><?= htmlspecialchars($location['path']) ?>:<?= htmlspecialchars(permissionUsageLinePreview($location['lines'])) ?></span>
            </span>
          <?php endforeach; ?>
          <?php if ($remainingLocations > 0): ?>
            <span class="permission-reference-chip">+<?= (int)$remainingLocations ?> more</span>
          <?php endif; ?>
          </div>
        </details>
      <?php endif; ?>
    </div>
    <?php
}
