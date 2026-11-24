<?php
// Local HTTP integration fixtures; never run against production.
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI checks only.');
ob_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
if (DB_HOST !== 'db' || strpos(BASE_URL, 'http://localhost:8080/') !== 0) throw new RuntimeException('Local Docker database only.');
$fixtureFile = '/tmp/gammavet-client-comments-fixture.json';
function accessCheck($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function fixtureCleanup(array $f): void {
    global $pdo;
    if (!$f) return;
    $pdo->prepare('DELETE FROM manufacturing_material_waste WHERE manufacturing_order_id = ?')->execute([$f['order']]);
    $pdo->prepare('DELETE FROM manufacturing_orders WHERE id = ?')->execute([$f['order']]);
    $pdo->prepare('DELETE FROM manufacturing_formulas WHERE id = ?')->execute([$f['formula']]);
    foreach ($f['inventories'] as $id) {
        $pdo->prepare('DELETE FROM inventory_stock_logs WHERE inventory_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM inventories WHERE id = ?')->execute([$id]);
    }
    foreach ($f['locations'] as $id) {
        $pdo->prepare('DELETE FROM inventories WHERE location_id = ? AND system_key IS NOT NULL')->execute([$id]);
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
    }
    foreach ($f['products'] as $id) $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    $pdo->prepare("DELETE FROM activity_logs WHERE user_id = ?")->execute([$f['user']]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$f['user']]);
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$f['role']]);
    $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$f['role']]);
    $session = session_save_path() ?: sys_get_temp_dir();
    if (is_file($session . '/sess_' . $f['session'])) unlink($session . '/sess_' . $f['session']);
    foreach ([$f['sample'], $f['report']] as $path) if (is_file(__DIR__ . '/../' . $path)) unlink(__DIR__ . '/../' . $path);
    if (isset($f['tag']) && preg_match('/^TEST-ACCESS-[a-f0-9]{10}$/D', $f['tag'])) {
        $directory = __DIR__ . '/../assets/uploads/manufacturing/' . manufacturing_slugify($f['tag']);
        foreach (glob($directory . '/*/*') ?: [] as $file) if (is_file($file)) unlink($file);
        foreach (glob($directory . '/*') ?: [] as $child) if (is_dir($child)) rmdir($child);
        if (is_dir($directory)) rmdir($directory);
    }
}
if (($argv[1] ?? '') === '--cleanup') {
    fixtureCleanup(json_decode(file_get_contents($fixtureFile), true)); unlink($fixtureFile); echo "Fixtures removed.\n"; exit;
}
$f = []; $retained = false; $keep = ($argv[1] ?? '') === '--setup';
try {
    if (is_file($fixtureFile)) throw new RuntimeException('Clean up the previous fixture first.');
    $tag = 'TEST-ACCESS-' . bin2hex(random_bytes(5)); $f['tag'] = $tag;
    $pdo->prepare('INSERT INTO roles (name,slug) VALUES (?,?)')->execute([$tag,$tag]); $f['role'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (username,password,name,email,role,role_id) VALUES (?,?,'Test access',?,'user',?)")->execute([$tag,password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),$tag.'@example.invalid',$f['role']]); $f['user'] = (int)$pdo->lastInsertId();
    $source = $pdo->query('SELECT customer_id,product_id FROM manufacturing_orders WHERE product_id IS NOT NULL LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $f['customer'] = (int)$source['customer_id']; $f['products'] = []; $f['inventories'] = []; $f['locations'] = [];
    $category = $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    for ($i=0;$i<30;$i++) {
        $pdo->prepare("INSERT INTO products (name,category_id,type,unit) VALUES (?,?,'material','each')")->execute([$tag.' material '.$i,$category]); $f['products'][] = (int)$pdo->lastInsertId();
    }
    for ($i=0;$i<2;$i++) {
        $pdo->prepare('INSERT INTO locations (name) VALUES (?)')->execute([$tag.' location '.$i]); $f['locations'][] = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO inventories (name,location_id) VALUES (?,?)')->execute([$tag.' inventory '.$i,$f['locations'][$i]]); $f['inventories'][] = (int)$pdo->lastInsertId();
    }
    foreach ($f['products'] as $id) $pdo->prepare('INSERT INTO inventory_products (inventory_id,product_id,quantity) VALUES (?,?,20)')->execute([$f['inventories'][0],$id]);
    $f['sample'] = 'assets/uploads/manufacturing/formula_samples/'.$tag.'.png';
    $f['report'] = 'assets/uploads/manufacturing/'.$tag.'.xlsx';
    if (!is_dir(dirname(__DIR__.'/../'.$f['sample']))) mkdir(dirname(__DIR__.'/../'.$f['sample']),0777,true);
    file_put_contents(__DIR__.'/../'.$f['sample'],base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6DkAAAAASUVORK5CYII='));
    file_put_contents(__DIR__.'/../'.$f['report'],'secret saved report');
    $pdo->prepare("INSERT INTO manufacturing_formulas (customer_id,name,description,batch_size,batch_unit,components_json,sample_images_json) VALUES (?,'ACCESS-SECRET-FORMULA','ACCESS-SECRET-DESCRIPTION',100,'L',?,?)")
        ->execute([$f['customer'],json_encode([['product_id'=>$f['products'][0],'name'=>'ACCESS-SECRET-INGREDIENT','quantity'=>1,'unit'=>'pcs']]),json_encode([['path'=>$f['sample']]])]); $f['formula']=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,location_id,batch_size,number_of_bottles,status) VALUES (?,?,?,?,?,100,100,'in_progress')")
        ->execute([$tag,$f['customer'],$source['product_id'],$f['formula'],$f['locations'][0]]); $f['order']=(int)$pdo->lastInsertId();
    foreach (manufacturing_get_step_definitions() as $key=>$definition) $pdo->prepare('INSERT INTO manufacturing_order_steps (manufacturing_order_id,step_key,label,status) VALUES (?,?,?,?)')->execute([$f['order'],$key,$key,$key==='delivering'?'pending':'completed']);
    $pdo->prepare("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id,formula_component_index,product_id,component_name,required_quantity,unit) VALUES (?,0,?,'ACCESS-SECRET-INGREDIENT',100,'pcs')")->execute([$f['order'],$f['products'][0]]);
    $basePermissions = ['region.factory','manufacturing.view','manufacturing.view_step_delivering','inventories.view'];
    $grant = function (array $keys) use ($pdo,&$f): void {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$f['role']]);
        $q=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE `key` = ?');
        foreach ($keys as $key) $q->execute([$f['role'],$key]);
    };
    $grant($basePermissions);
    session_id('access'.bin2hex(random_bytes(16)));session_start();
    $f['session']=session_id();
    $_SESSION=['user_id'=>$f['user'],'user_name'=>'Test access','session_version'=>0,'login_region'=>'factory'];session_write_close();
    chmod((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $f['session'], 0666);
    $request=function(string $url, ?array $post=null) use (&$f): array {
        $http=['method'=>$post===null?'GET':'POST','header'=>"Cookie: PHPSESSID={$f['session']}\r\nConnection: close\r\n",'ignore_errors'=>true,'follow_location'=>0,'timeout'=>25];
        if ($post!==null) { $http['header'].="Content-Type: application/x-www-form-urlencoded\r\n"; $http['content']=http_build_query($post); }
        $body=file_get_contents('http://127.0.0.1/'.$url,false,stream_context_create(['http'=>$http]));

        preg_match('/\s(\d{3})\s/',$http_response_header[0],$m);return [(int)$m[1],(string)$body];
    };
    $url='modules/manufacturing/order.php?id='.$f['order'];
    [$status,$html]=$request($url); accessCheck($status===200 && str_contains($html, 'Order snapshot') && !str_contains($html,'Fatal error'),'Driver order page renders');
    accessCheck(str_contains($html, 'Shipment verification') && str_contains($html, 'Total quantity') && str_contains($html, 'Cartons'), 'Driver sees shipment quantities and cartons');
    [$deliveryStatus,$deliveryHtml]=$request('modules/manufacturing/delivery.php?search='.urlencode($tag));
    accessCheck($deliveryStatus===200 && str_contains($deliveryHtml, $tag) && !str_contains($deliveryHtml,'ACCESS-SECRET-'), 'Driver grouped delivery page renders without formula details');
    accessCheck($request('modules/manufacturing/index.php')[0]===302, 'Manufacturing menu sends delivery-only users to grouped deliveries');
    accessCheck($request('modules/manufacturing/index.php?view=manufacturing')[0]===200, 'Driver can still open the full manufacturing list explicitly');
    foreach (['ACCESS-SECRET-','Batch size:','Formula samples:','Manufacturing audit /','name="action" value="complete_order"','bottle_sizes.php','packaging_options.php'] as $secret) accessCheck(!str_contains($html,$secret),'Driver response omits '.$secret);
    preg_match('/name="manufacturing_csrf" value="([^"]+)"/',$html,$match);$token=$match[1];
    $request($url,['manufacturing_csrf'=>$token,'action'=>'complete_order','finished_goods_inventory_id'=>$f['inventories'][0]]);
    $q=$pdo->prepare('SELECT status,finished_goods_added_at FROM manufacturing_orders WHERE id = ?');$q->execute([$f['order']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    accessCheck($row['status']==='in_progress' && $row['finished_goods_added_at']===null,'Forged driver completion changes no stock or order state');
    $request($url,['manufacturing_csrf'=>$token,'action'=>'update','step_key'=>'delivering','status'=>'completed']);
    $q->execute([$f['order']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    accessCheck($row['status']==='in_progress' && $row['finished_goods_added_at']===null,'Driver last-step save does not finalize or post stock');
    accessCheck($pdo->query("SELECT status FROM manufacturing_order_steps WHERE manufacturing_order_id={$f['order']} AND step_key='delivering'")->fetchColumn()==='completed','Driver can complete their delivery step');
    foreach (['formulas.php','bottle_sizes.php','bottle_size_edit.php','packaging_options.php','packaging_option_edit.php'] as $page) accessCheck($request('modules/manufacturing/'.$page)[0]!==200,'Direct setup access denied: '.$page);
    accessCheck($request('ajax/get_manufacturing_formulas.php?provider_id='.$f['customer'])[0]===403,'Driver recipe AJAX denied');
    $sampleUrl='modules/manufacturing/formula_sample.php?id='.$f['formula'].'&image='.hash('sha256',$f['sample']);
    accessCheck($request($sampleUrl)[0]===403,'Protected sample requires formula permission');
    accessCheck($request($f['sample'])[0]===403 && $request($f['report'])[0]===403,'Static sample and report URLs are denied');
    [$status,$body]=$request('modules/manufacturing/view_report.php?id='.$f['order'].'&step=delivering&format=excel');
    accessCheck($status===200 && str_starts_with($body,'PK'),'Driver can download a permitted Excel handoff');
    [$status,$inventoryHtml]=$request('modules/inventories/view.php?id='.$f['inventories'][0]);
    preg_match('/name="inventory_request_token" value="([^"]+)"/',$inventoryHtml,$inventoryToken);
    $deleteInput=['inventory_request_token'=>$inventoryToken[1],'inventory_id'=>$f['inventories'][0],'product_id'=>$f['products'][0]];
    accessCheck($request('modules/inventories/delete_product.php',$deleteInput)[0]!==200,'Read-only account cannot remove an item with a valid token');
    accessCheck($request('modules/inventories/bulk_delete_products.php',array_merge($deleteInput,['product_ids'=>[$f['products'][0]]]))[0]!==200,'Read-only account cannot bulk-remove items');
    accessCheck($request('modules/inventories/index.php',['inventory_request_token'=>$inventoryToken[1],'delete'=>$f['inventories'][1]])[0]===403,'Read-only account cannot delete an empty warehouse');
    $grant(array_merge($basePermissions,['manufacturing.formula.view_all','manufacturing.component.name.view','manufacturing.audit.view','manufacturing.orders.technical_details.view','manufacturing.view_step_sourcing','manufacturing.view_step_receipt','manufacturing.view_step_preparation']));
    [$status,$body]=$request($url);
    accessCheck(!str_contains($body,'ACCESS-SECRET-INGREDIENT') && !str_contains($body,'Manufacturing audit /'),'Names, audit and step permissions cannot bypass formula restrictions');
    foreach (['sourcing','receipt','preparation'] as $key) accessCheck(str_contains($body, 'id="step-'.$key.'"'), 'Locked formula retains the '.$key.' workflow step');
    $grant(array_merge($basePermissions,['manufacturing.orders.create']));
    [$status,$body]=$request('ajax/get_manufacturing_formulas.php?provider_id='.$f['customer']);$lookup=json_decode($body,true);
    accessCheck($status===200 && !str_contains($body,'ACCESS-SECRET-INGREDIENT') && !str_contains($body,'sample_images') && !str_contains($body,'batch_size'),'Creator lookup does not return recipes or samples');
    $grant(array_merge($basePermissions,['manufacturing.formula.view_all','manufacturing.orders.technical_details.view','manufacturing.audit.view','manufacturing.orders.complete']));
    accessCheck($request($sampleUrl)[0]===403,'Formula permission still requires unlock');
    session_id($f['session']);session_start();$_SESSION['formula_unlocked']=true;$_SESSION['formula_password_version']=formulaPasswordVersion();session_write_close();
    [$status,$body]=$request($url); accessCheck($status===200 && str_contains($body,'ACCESS-SECRET-INGREDIENT') && str_contains($body,'Manufacturing audit /'),'Authorized unlocked viewer sees formula and audit');
    accessCheck($request($sampleUrl)[0]===200,'Authorized unlocked sample renders');
    preg_match('/name="manufacturing_csrf" value="([^"]+)"/',$body,$match);
    $request($url,['manufacturing_csrf'=>$match[1],'action'=>'complete_order','finished_goods_inventory_id'=>$f['inventories'][0]]);
    $q->execute([$f['order']]);$row=$q->fetch(PDO::FETCH_ASSOC);accessCheck($row['status']==='completed' && $row['finished_goods_added_at']!==null,'Authorized user finalizes after driver completes delivery');
    $stockCheck=$pdo->prepare('SELECT SUM(quantity) FROM inventory_products WHERE inventory_id = ?'); $stockCheck->execute([$f['inventories'][0]]); $before=$stockCheck->fetchColumn();
    $request($url,['manufacturing_csrf'=>$match[1],'action'=>'complete_order','finished_goods_inventory_id'=>$f['inventories'][0]]);
    $stockCheck->execute([$f['inventories'][0]]);accessCheck($stockCheck->fetchColumn()===$before,'Repeated HTTP completion cannot duplicate stock');
    $grant($basePermissions);accessCheck($request($sampleUrl)[0]===403,'Revocation takes effect on existing session');
    $grant(array_merge($basePermissions,['inventories.edit','inventories.delete','manufacturing.formula.view_all','manufacturing.orders.technical_details.view','manufacturing.bottle_sizes.view']));
    accessCheck($request('modules/manufacturing/bottle_sizes.php')[0]===200,'Catalog view permission opens bottle sizes');
    accessCheck($request('modules/manufacturing/bottle_size_edit.php')[0]!==200,'Catalog view permission cannot manage bottle sizes');
    [$status,$body]=$request('modules/inventories/index.php?location_id='.$f['locations'][0]);
    accessCheck($status===200 && str_contains($body,$tag.' inventory 0') && !str_contains($body,$tag.' inventory 1</td>'),'Location filter limits warehouse results');
    accessCheck($request('modules/inventories/index.php?location_id=999999999')[0]===400,'Unknown location filter rejected');
    accessCheck($request('modules/inventories/delete_product.php?inventory_id='.$f['inventories'][0].'&product_id='.$f['products'][0])[0]===403,'GET cannot delete inventory items');
    accessCheck($request('modules/inventories/delete_product.php',['inventory_id'=>$f['inventories'][0],'product_id'=>$f['products'][0]])[0]===403,'Missing CSRF token cannot delete inventory items');
    if (!$keep) {
        // CureVet's location must not be offered or accepted in the Factory channel.
        $cureLocation = $pdo->query("SELECT id FROM locations WHERE LOWER(REPLACE(TRIM(name),' ','')) = 'curevet' LIMIT 1")->fetchColumn();
        if (!$cureLocation) {
            $pdo->exec("INSERT INTO locations (name,is_active) VALUES ('Cure Vet',1)");
            $cureLocation = (int)$pdo->lastInsertId();
            $f['locations'][] = $cureLocation;
        }
        $grant(array_merge($basePermissions, ['manufacturing.orders.create','manufacturing.formula.view_all',
            'manufacturing.orders.technical_details.view','manufacturing.view_step_sourcing','locations.view','products.view']));
        foreach (['modules/manufacturing/create.php','modules/manufacturing/sourcing_list.php','modules/inventories/index.php'] as $page) {
            [$pageStatus,$pageBody] = $request($page);
            accessCheck($pageStatus===200 && !str_contains($pageBody,'Fatal error'), 'Factory page renders: '.$page);
            preg_match_all('/<select\b[^>]*(?:name|id)=["\'][^"\']*location[^"\']*["\'][^>]*>.*?<\/select>/s', $pageBody, $locationSelects);
            accessCheck(count($locationSelects[0]) > 0 && !preg_match('/<option[^>]*value=["\']'.(int)$cureLocation.'["\']/', implode('', $locationSelects[0])),
                'CureVet location omitted from '.$page);
        }
        $q = $pdo->prepare('SELECT COUNT(*) FROM manufacturing_orders WHERE created_by = ?');
        $q->execute([$f['user']]); $ordersBefore = (int)$q->fetchColumn();
        [$ajaxStatus,$ajaxBody] = $request('ajax/create_manufacturing_order.php', ['customer_id'=>$f['customer'],
            'product_id'=>$source['product_id'],'formula_id'=>$f['formula'],'location_id'=>$cureLocation,'batch_size'=>100]);
        $q->execute([$f['user']]);
        accessCheck((int)$q->fetchColumn()===$ordersBefore && (json_decode($ajaxBody,true)['success'] ?? true)===false,
            'Forged manufacturing AJAX cannot use the CureVet location');
        $request('modules/manufacturing/create.php', ['customer_id'=>$f['customer'],'product_id'=>$source['product_id'],
            'formula_id'=>$f['formula'],'location_id'=>$cureLocation,'number_of_bottles'=>100]);
        $q->execute([$f['user']]);
        accessCheck((int)$q->fetchColumn()===$ordersBefore, 'Manufacturing form rejects the hidden CureVet location');
        // Exercise stock changes through the actual workflow handler as well as helper tests.
        $grant(array_merge($basePermissions, ['manufacturing.formula.view_all', 'manufacturing.orders.technical_details.view',
            'manufacturing.view_step_sourcing', 'manufacturing.view_step_receipt']));
        $pdo->prepare("UPDATE manufacturing_orders SET status = 'in_progress', finished_goods_added_at = NULL WHERE id = ?")->execute([$f['order']]);
        $pdo->prepare("UPDATE manufacturing_order_steps SET status = 'pending' WHERE manufacturing_order_id = ?")->execute([$f['order']]);
        $pdo->prepare('UPDATE inventories SET location_id = ? WHERE id = ?')->execute([$f['locations'][0], $f['inventories'][1]]);
        $pdo->prepare('INSERT INTO inventory_products (inventory_id,product_id,quantity) VALUES (?,?,20)')->execute([$f['inventories'][1], $f['products'][0]]);
        $pdo->prepare('UPDATE manufacturing_formulas SET components_json = ? WHERE id = ?')->execute([
            json_encode([['product_id'=>$f['products'][0], 'name'=>'ACCESS-SECRET-INGREDIENT', 'quantity'=>30, 'unit'=>'pcs']]), $f['formula']]);
        [$status,$body]=$request($url);
        accessCheck($status===200 && !str_contains($body,'Fatal error'), 'Sourcing page renders with stock unit conversion');
        preg_match('/name="manufacturing_csrf" value="([^"]+)"/', $body, $stockToken);
        $component = $pdo->query('SELECT id FROM manufacturing_sourcing_components WHERE manufacturing_order_id = ' . $f['order'])->fetchColumn();
        $balances = function () use ($pdo, &$f): array {
            $q = $pdo->prepare('SELECT quantity FROM inventory_products WHERE product_id = ? AND inventory_id IN (?,?) ORDER BY inventory_id');
            $q->execute([$f['products'][0], $f['inventories'][0], $f['inventories'][1]]);
            return array_map('floatval', $q->fetchAll(PDO::FETCH_COLUMN));
        };
        $sourcePost = ['manufacturing_csrf'=>$stockToken[1], 'action'=>'update', 'step_key'=>'sourcing', 'status'=>'completed'];
        $request($url, $sourcePost);
        accessCheck($balances()===[0.0,10.0], 'HTTP sourcing deducts 30 total across two warehouses');
        $request($url, $sourcePost);
        accessCheck($balances()===[0.0,10.0], 'Repeated HTTP sourcing cannot deduct again');
        $reject = ['manufacturing_csrf'=>$stockToken[1], 'action'=>'update', 'step_key'=>'receipt', 'status'=>'completed',
            'receipt_status'=>[$component=>'rejected']];
        $request($url, $reject);
        accessCheck($balances()===[0.0,10.0], 'Failed receipt completion rolls back its proposed stock return');
        $reject['status']='in_progress';
        $request($url, $reject);
        accessCheck($balances()===[20.0,20.0], 'HTTP rejection returns exact original warehouse allocations');
        $request($url, $reject);
        accessCheck($balances()===[20.0,20.0], 'Repeated HTTP rejection cannot return stock twice');
        $pdo->prepare("UPDATE manufacturing_order_steps SET status = 'pending' WHERE manufacturing_order_id = ? AND step_key = 'sourcing'")->execute([$f['order']]);
        $request($url, $sourcePost);
        accessCheck($balances()===[0.0,10.0], 'HTTP resourcing deducts the returned material once');
        // Actual packaging rows share the sourced component identity and post only on completion.
        $grant(array_merge($basePermissions, ['manufacturing.view_step_packaging', 'manufacturing.view_step_dispatch', 'manufacturing.orders.technical_details.view']));
        $pdo->prepare("UPDATE manufacturing_order_steps SET status = 'completed' WHERE manufacturing_order_id = ? AND step_key IN ('receipt','preparation','quality')")->execute([$f['order']]);
        $pdo->prepare('UPDATE inventory_products SET quantity = 30 WHERE inventory_id = ? AND product_id = ?')->execute([$f['inventories'][0], $f['products'][1]]);
        $pdo->prepare("INSERT INTO manufacturing_sourcing_components
            (manufacturing_order_id,source,formula_component_index,product_id,component_name,required_quantity,unit,receipt_status)
            VALUES (?,'packaging',0,?,'Actual packing bottle',20,'pcs','approved')")->execute([$f['order'],$f['products'][1]]);
        $packingComponent = (int)$pdo->lastInsertId();
        $pdo->beginTransaction();
        manufacturing_deduct_sourcing_stock($pdo, $f['order']);
        $pdo->commit();
        $packingBalance = function () use ($pdo, &$f): float {
            $q = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ?');
            $q->execute([$f['inventories'][0],$f['products'][1]]);
            return (float)$q->fetchColumn();
        };
        [$status,$body] = $request($url);
        accessCheck($status===200 && str_contains($body,'data-component-id="'.$packingComponent.'"')
            && str_contains($body,'Actual packing bottle') && !str_contains($body,'ACCESS-SECRET-INGREDIENT')
            && !str_contains($body,'name="packaging_values[prints_issued]"'), 'HTTP material table shows packaging material names while hiding formula ingredients');
        $packingPost = ['manufacturing_csrf'=>$stockToken[1], 'action'=>'update', 'step_key'=>'packaging', 'status'=>'in_progress',
            'packaging_values'=>['final_product_count'=>'110']];
        $request($url, $packingPost);
        accessCheck($packingBalance()===10.0, 'HTTP draft final count previews extra usage without moving stock');
        $packingPost['status'] = 'completed';
        $packingPost['packaging_values']['final_product_count'] = '90';
        $packingPost['packaging_materials'] = [$packingComponent=>['issued'=>'20','used'=>'18','waste'=>'0']];
        $request($url, $packingPost);
        accessCheck($packingBalance()===12.0 && $balances()===[0.0,10.0], 'HTTP completion returns unused packaging and leaves raw ingredient stock unchanged');
        $tracking = manufacturing_packaging_material_rows($conn, $f['order']);
        accessCheck(count($tracking)===1 && (float)$tracking[0]['used_quantity']===18.0
            && (float)$tracking[0]['returned_quantity']===2.0 && $tracking[0]['posted_at'], 'Dispatch and reports read the saved per-material usage and راجع');
        $request($url, $packingPost);
        accessCheck($packingBalance()===12.0, 'Repeated HTTP packaging completion cannot duplicate a return');
        $grant(array_merge($basePermissions, ['manufacturing.edit', 'manufacturing.orders.technical_details.view']));
        $request('modules/manufacturing/edit.php?id='.$f['order'], ['location_id'=>$f['locations'][1],
            'product_id'=>$source['product_id'], 'batch_size'=>100, 'priority'=>'normal', 'cancel_order'=>1]);
        accessCheck($balances()===[20.0,20.0]
            && $pdo->query('SELECT status FROM manufacturing_orders WHERE id = '.$f['order'])->fetchColumn()==='cancelled',
            'HTTP cancellation restores original warehouses even when a different location is submitted');
        accessCheck($packingBalance()===30.0, 'HTTP cancellation restores only packaging still consumed after its earlier return');
    }
    if ($keep) { $retained = true; file_put_contents($fixtureFile,json_encode($f));echo "Browser fixture ready.\n"; }
} finally {
    if (!$retained && isset($f['order'])) fixtureCleanup($f);
}
