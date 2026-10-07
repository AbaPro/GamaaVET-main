<?php
// Keep CSV values readable and prevent text from being interpreted as formulas.
function exportCsvValue($value): string {
    $text = (string)($value ?? '');
    $trimmed = ltrim($text);
    if (preg_match('/^[=+@]/u', $trimmed)
        || (strpos($trimmed, '-') === 0 && !preg_match('/^-\d+(?:[.,]\d+)?$/D', trim($text)))) {
        return "'" . $text;
    }
    return $text;
}

function exportCsvRow($output, array $values): void {
    fputcsv($output, array_map('exportCsvValue', $values), ',', '"', '');
}

function exportText($value): string {
    // Formatted raw text preserves identifiers and wraps descriptions and notes.
    return '<wraptext><raw>' . htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8') . '</raw></wraptext>';
}

function exportColumnName(int $index): string {
    $name = '';
    do {
        $name = chr(65 + $index % 26) . $name;
        $index = intdiv($index, 26) - 1;
    } while ($index >= 0);
    return $name;
}

function exportWorkbookSheet($xlsx, array $data, string $name, int $headerRow = 1): void {
    $headers = $data[$headerRow - 1];
    foreach ($headers as $index => $header) {
        $label = htmlspecialchars(strip_tags($header), ENT_QUOTES, 'UTF-8');
        $data[$headerRow - 1][$index] = '<b><wraptext><style bgcolor="1F4E79" color="FFFFFF" height="30">' . $label . '</style></wraptext></b>';
    }
    foreach ($data as $rowIndex => &$row) {
        if ($rowIndex < $headerRow) continue;
        foreach ($row as $index => &$value) {
            if ((is_float($value) || is_int($value))
                && preg_match('/Price|Cost|Value|Total|Paid|Balance|Discount|Shipping|Inflow|Outflow|Transfer/', strip_tags($headers[$index] ?? ''))) {
                $value = '<style nf="#,##0.00">' . $value . '</style>';
            }
        }
        unset($value);
    }
    unset($row);
    $xlsx->addSheet($data, $name)->freezePanes('A' . ($headerRow + 1));
    // Exclude summary rows from filtering.
    $lastDataRow = $headerRow;
    for ($i = $headerRow; $i < count($data); $i++) {
        if (count($data[$i]) === 1 && $data[$i][0] === '') break;
        $lastDataRow = $i + 1;
    }
    if ($lastDataRow > $headerRow) {
        $xlsx->autoFilter('A' . $headerRow . ':' . exportColumnName(count($headers) - 1) . $lastDataRow);
    }
    foreach ($headers as $index => $header) {
        $label = strip_tags($header);
        $width = preg_match('/Notes?|Description|Product|Customer|Vendor|Account/', $label) ? 32 : max(16, min(24, strlen($label) + 3));
        $xlsx->setColWidth($index + 1, $width);
    }
}
