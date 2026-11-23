<?php
// Local HTTP workflow fixtures. Every created record and generated document is removed.
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI checks only.');
ob_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
if (DB_HOST !== 'db' || !str_starts_with(BASE_URL, 'http://localhost:8080/')) throw new RuntimeException('Local Docker only.');
function primaryHttpCheck(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$fixture = ['products'=>[], 'orders'=>[], 'manufacturing'=>[], 'locations'=>[]];
$tag = 'TEST-PRIMARY-HTTP-' . bin2hex(random_bytes(5));
try {
    $role = (int)$pdo->query("SELECT id FROM roles WHERE slug='admin'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (username,password,name,email,role,role_id) VALUES (?,?,'Primary HTTP test',?,'admin',?)")
        ->execute([$tag,password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),$tag.'@example.invalid',$role]);
    $fixture['user']=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (name,type) VALUES (?,'factory')")->execute([$tag]);
    $fixture['customer']=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customer_contacts (customer_id,name,phone,is_primary) VALUES (?,'Test contact','01000000000',1)")->execute([$fixture['customer']]);
    $fixture['contact']=(int)$pdo->lastInsertId();
    $category=(int)$pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    foreach ([['final','each'],['material','each'],['material','kilo']] as [$type,$unit]) {
        $pdo->prepare('INSERT INTO products (name,category_id,type,unit,customer_id) VALUES (?,?,?,?,?)')->execute([$tag.' '.count($fixture['products']),$category,$type,$unit,$fixture['customer']]);
        $fixture['products'][]=(int)$pdo->lastInsertId();
    }
    [$final,$bottle,$raw]=$fixture['products'];
    foreach (['one','two'] as $name) {
        $pdo->prepare('INSERT INTO locations (name) VALUES (?)')->execute([$tag.' '.$name]);
        $fixture['locations'][]=(int)$pdo->lastInsertId();
    }
    $location=$fixture['locations'][0];
    $pdo->prepare("INSERT INTO inventories (name,location_id) VALUES (?,?)")->execute([$tag,$location]);
    $fixture['inventory']=(int)$pdo->lastInsertId();
    foreach ([$bottle,$raw] as $product) $pdo->prepare('INSERT INTO inventory_products (inventory_id,product_id,quantity) VALUES (?,?,200)')->execute([$fixture['inventory'],$product]);
    $pdo->prepare("INSERT INTO manufacturing_formulas (customer_id,product_id,name,batch_size,batch_unit,components_json) VALUES (?,?,?,100,'L',?)")
        ->execute([$fixture['customer'],$final,$tag,json_encode([['product_id'=>$raw,'name'=>'Test ingredient','quantity'=>1,'unit'=>'kg']])]);
    $fixture['formula']=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO bottle_sizes (name,size,unit,type) VALUES (?,1,'L','liquid')")->execute([$tag]);
    $fixture['bottle_size']=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO packaging_options (customer_id,product_id,name) VALUES (?,?,?)')->execute([$fixture['customer'],$final,$tag]);
    $fixture['kit']=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO packaging_option_items (packaging_option_id,product_id,quantity,unit) VALUES (?,?,1,'pcs')")->execute([$fixture['kit'],$bottle]);
    $pdo->prepare("INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,location_id,bottle_size_id,number_of_bottles,batch_size,packaging_option_id,status,created_by)
        VALUES (?,?,?,?,?,?,100,100,?,'in_progress',?)")->execute([$tag,$fixture['customer'],$final,$fixture['formula'],$location,$fixture['bottle_size'],$fixture['kit'],$fixture['user']]);
    $initial=(int)$pdo->lastInsertId();$fixture['manufacturing'][]=$initial;
    foreach (manufacturing_get_step_definitions() as $key=>$definition) $pdo->prepare('INSERT INTO manufacturing_order_steps (manufacturing_order_id,step_key,label,status) VALUES (?,?,?,?)')
        ->execute([$initial,$key,$definition['label'],in_array($key,['sourcing','receipt','preparation','quality'],true)?'completed':'pending']);
    manufacturing_sync_sourcing($initial); // Completed sourcing is protected: populate before deducting via preview instead.
    $pdo->prepare("UPDATE manufacturing_order_steps SET status='pending' WHERE manufacturing_order_id=? AND step_key='sourcing'")->execute([$initial]);
    manufacturing_sync_sourcing($initial);
    session_id('primaryhttp'.bin2hex(random_bytes(12)));session_start();
    $fixture['session']=session_id();
    $_SESSION=['user_id'=>$fixture['user'],'session_version'=>0,'login_region'=>'factory','role_slug'=>'admin','permissions'=>[]];
    $pdo->beginTransaction();manufacturing_deduct_sourcing_stock($pdo,$initial);$pdo->commit();
    $pdo->prepare("UPDATE manufacturing_order_steps SET status='completed' WHERE manufacturing_order_id=? AND step_key='sourcing'")->execute([$initial]);
    session_write_close();chmod((session_save_path() ?: sys_get_temp_dir()).'/sess_'.$fixture['session'],0666);
    $request=function(string $path,?array $post=null) use (&$fixture): array {
        $opts=['method'=>$post===null?'GET':'POST','header'=>"Cookie: PHPSESSID={$fixture['session']}\r\nConnection: close\r\n",'ignore_errors'=>true,'follow_location'=>0,'timeout'=>25];
        if ($post!==null) { $opts['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$opts['content']=http_build_query($post); }
        $body=file_get_contents('http://127.0.0.1/'.$path,false,stream_context_create(['http'=>$opts]));
        preg_match('/\s(\d{3})\s/',$http_response_header[0],$match);return [(int)$match[1],(string)$body];
    };
    $url='modules/manufacturing/order.php?id='.$initial;
    [$status,$html]=$request($url);
    primaryHttpCheck($status===200 && !str_contains($html,'Fatal error'),'Actual packaging page renders');
    preg_match('/name="manufacturing_csrf" value="([^"]+)"/',$html,$token);
    $component=(int)$pdo->query("SELECT id FROM manufacturing_sourcing_components WHERE manufacturing_order_id=$initial AND source='packaging'")->fetchColumn();
    $post=['manufacturing_csrf'=>$token[1],'action'=>'update','step_key'=>'packaging','status'=>'completed',
        'packaging_values'=>['final_product_count'=>'100'], 'packaging_materials'=>[$component=>['issued'=>'100','used'=>'80','waste'=>'5']]];
    $request($url,$post);
    $primary=manufacturing_primary_availability($pdo,$final,$location);
    $fixture['primary']=(int)$primary['primary_product_id'];
    primaryHttpCheck((int)$primary['quantity']===20,'HTTP packaging creates a separate primary product and stocks 20 at the same location');
    $output=$pdo->query("SELECT finished_quantity FROM manufacturing_packaging_output WHERE manufacturing_order_id=$initial")->fetchColumn();
    primaryHttpCheck((int)$output===80,'HTTP packaging retains 80 as finished output');
    $wasteInventory=(int)$pdo->query("SELECT id FROM inventories WHERE system_key='manufacturing_waste' AND location_id=$location")->fetchColumn();
    primaryHttpCheck(canAddProductToInventory($wasteInventory,$bottle) && !canAddProductToInventory($wasteInventory,$final),
        'Waste warehouse accepts a real packaging SKU and rejects a final-product SKU');
    $request('modules/products/assemble.php',['final_product_id'=>$final,'inventory_id'=>$wasteInventory,'quantity'=>'1']);
    $stmt=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory_products WHERE inventory_id=? AND product_id=?');
    $stmt->execute([$wasteInventory,$final]);
    primaryHttpCheck((float)$stmt->fetchColumn()===0.0,'Forged assembly cannot put a final product into the waste warehouse');
    $request('modules/products/edit.php',['id'=>$bottle,'name'=>$tag.' bottle','sku'=>$tag.'-B','barcode'=>'',
        'category_id'=>$category,'customer_id'=>$fixture['customer'],'type'=>'final','unit'=>'each']);
    primaryHttpCheck($pdo->query("SELECT type FROM products WHERE id=$bottle")->fetchColumn()==='material',
        'Material already held in waste cannot be reclassified as a final product');
    [$status,$body]=$request('ajax/get_primary_stock.php?product_id='.$final.'&location_id='.$location);
    primaryHttpCheck($status===200 && (int)json_decode($body,true)['quantity']===20,'Manufacturing availability endpoint shows primary stock for the selected location');
    [$status,$body]=$request('ajax/get_primary_stock.php?product_id='.$final.'&location_id='.$fixture['locations'][1]);
    primaryHttpCheck($status===200 && (int)json_decode($body,true)['quantity']===0,'Another location cannot see or consume those units as its own stock');
    [$status,$body]=$request('modules/sales/create_order.php');
    primaryHttpCheck($status===200 && str_contains($body,'data-primary="20"') && !str_contains($body,'Fatal error'),'Sales product picker offers existing primary stock');
    $salesPost=['internal_id'=>$tag,'customer_id'=>$fixture['customer'],'contact_id'=>$fixture['contact'],'order_date'=>'2026-11-21',
        'items'=>[['product_id'=>$final,'quantity'=>'30','price'=>'10','primary_stock_quantity'=>'20']]];
    $request('modules/sales/create_order.php',$salesPost);
    $sales=(int)$pdo->query('SELECT id FROM orders WHERE created_by='.(int)$fixture['user'].' ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($sales) $fixture['orders'][]=$sales;
    primaryHttpCheck($sales>0,'Sales order saves the selected primary stock preference');
    $item=$pdo->query("SELECT * FROM order_items WHERE order_id=$sales")->fetch(PDO::FETCH_ASSOC);
    primaryHttpCheck((int)$item['primary_stock_quantity']===20 && (float)$item['total_price']===300.0,'Primary preference does not change sales prices or totals');
    [$status,$body]=$request('modules/sales/order_details.php?id='.$sales);
    primaryHttpCheck($status===200 && str_contains($body,'Use existing primary stock at this location') && !str_contains($body,'Fatal error'),'Sales order details offer a location-specific reuse action');
    $link='modules/manufacturing/create.php?sales_order_id='.$sales.'&sales_order_item_id='.$item['id'].'&location_id='.$location;
    [$status,$body]=$request($link);
    primaryHttpCheck($status===200 && str_contains($body,'name="primary_stock_quantity" value="20"'),'Linked manufacturing form carries the sales preference into the selected location');
    $request($link,['sales_order_id'=>$sales,'sales_order_item_id'=>$item['id'],'customer_id'=>$fixture['customer'],'product_id'=>$final,
        'formula_id'=>$fixture['formula'],'location_id'=>$location,'bottle_size_id'=>$fixture['bottle_size'],'number_of_bottles'=>'30',
        'packaging_option_id'=>$fixture['kit'],'primary_stock_quantity'=>'20']);
    $reuse=(int)$pdo->query("SELECT id FROM manufacturing_orders WHERE sales_order_id=$sales ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($reuse) $fixture['manufacturing'][]=$reuse;
    primaryHttpCheck($reuse>0 && (int)$pdo->query("SELECT primary_stock_quantity FROM manufacturing_orders WHERE id=$reuse")->fetchColumn()===20,
        'Linked manufacturing order saves the explicit primary reuse choice');
    [$status,$body]=$request('modules/manufacturing/order.php?id='.$reuse);
    primaryHttpCheck((bool)preg_match('/name="packaging_values\[primary_product_used\]"\s+value="20"/',$body),
        'بعد التعبئة includes منتج اولي مستخدم prefilled with the selected primary stock quantity');
    preg_match('/name="manufacturing_csrf" value="([^"]+)"/',$body,$reuseToken);
    $request('modules/manufacturing/order.php?id='.$reuse,['manufacturing_csrf'=>$reuseToken[1],'action'=>'update','step_key'=>'sourcing','status'=>'completed']);
    primaryHttpCheck((int)manufacturing_primary_availability($pdo,$final,$location)['quantity']===0,'HTTP sourcing consumes selected primary stock only at its own location');
    $fresh=(float)$pdo->query("SELECT required_quantity FROM manufacturing_sourcing_components WHERE manufacturing_order_id=$reuse AND source='formula'")->fetchColumn();
    primaryHttpCheck($fresh===0.1,'Reuse consumes ingredients for only the remaining 10 products');
    $pdo->prepare("UPDATE manufacturing_order_steps SET status='completed' WHERE manufacturing_order_id=? AND step_key IN ('receipt','preparation','quality')")->execute([$reuse]);
    $packingId=(int)$pdo->query("SELECT id FROM manufacturing_sourcing_components WHERE manufacturing_order_id=$reuse AND source='packaging'")->fetchColumn();
    $request('modules/manufacturing/order.php?id='.$reuse,['manufacturing_csrf'=>$reuseToken[1],'action'=>'update','step_key'=>'packaging','status'=>'completed',
        'packaging_values'=>['final_product_count'=>'30','primary_product_used'=>'15'],
        'packaging_materials'=>[$packingId=>['issued'=>'30','used'=>'30','waste'=>'0']]]);
    primaryHttpCheck((int)manufacturing_primary_availability($pdo,$final,$location)['quantity']===5,
        'Saving actual منتج اولي مستخدم returns unused selected units to the same location');
    $counts=manufacturing_delivery_counts($conn,$reuse);
    primaryHttpCheck($counts['primary_product_used']==='15','Dispatch and delivery read the actual saved primary usage');
    [$status,$body]=$request('modules/manufacturing/order.php?id='.$reuse);
    primaryHttpCheck($status===200 && str_contains($body,'منتج اولي مستخدم: 15'),'Manufacturing summary shows actual primary usage');
    [$status,$body]=$request('modules/sales/order_details.php?id='.$sales);
    primaryHttpCheck($status===200 && str_contains($body,'منتج اولي مستخدم: 15'),'Linked sales order shows the actual primary usage');
    $groups=manufacturing_delivery_groups($conn,$sales);
    primaryHttpCheck(str_contains(manufacturing_delivery_group_html($groups['sales-'.$sales]),'<th>15</th>'),
        'Grouped deliveries include the primary usage column and total');
    $step=$pdo->query("SELECT * FROM manufacturing_order_steps WHERE manufacturing_order_id=$reuse AND step_key='packaging'")->fetch(PDO::FETCH_ASSOC);
    $reportOrder=$pdo->query("SELECT mo.*, c.name AS customer_name FROM manufacturing_orders mo JOIN customers c ON c.id=mo.customer_id WHERE mo.id=$reuse")->fetch(PDO::FETCH_ASSOC);
    $report=manufacturing_build_step_document_html($reportOrder,$step,[],[],'','Packaging',$conn,false);
    primaryHttpCheck(str_contains($report,'منتج اولي مستخدم:') && str_contains($report,'15</td>'),
        'PDF and Excel handoff content includes actual primary usage');
    echo "All primary HTTP workflow checks passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Discover generated orders even if an assertion interrupted the workflow.
    if (isset($fixture['user'])) {
        $stmt=$pdo->prepare('SELECT id,order_number FROM manufacturing_orders WHERE created_by=?');$stmt->execute([$fixture['user']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $directory=__DIR__.'/../assets/uploads/manufacturing/'.manufacturing_slugify($order['order_number']);
            foreach (glob($directory.'/*/*') ?: [] as $file) if (is_file($file)) unlink($file);
            foreach (glob($directory.'/*') ?: [] as $child) if (is_dir($child)) rmdir($child);
            if (is_dir($directory)) rmdir($directory);
            $pdo->prepare('DELETE FROM manufacturing_material_waste WHERE manufacturing_order_id=?')->execute([$order['id']]);
            $pdo->prepare('DELETE FROM manufacturing_orders WHERE id=?')->execute([$order['id']]);
        }
        $stmt=$pdo->prepare('SELECT id FROM orders WHERE created_by=?');$stmt->execute([$fixture['user']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $order) { $pdo->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$order]);$pdo->prepare('DELETE FROM orders WHERE id=?')->execute([$order]); }
    }
    if (isset($fixture['formula'])) $pdo->prepare('DELETE FROM manufacturing_formulas WHERE id=?')->execute([$fixture['formula']]);
    if (isset($fixture['kit'])) $pdo->prepare('DELETE FROM packaging_options WHERE id=?')->execute([$fixture['kit']]);
    if (isset($fixture['bottle_size'])) $pdo->prepare('DELETE FROM bottle_sizes WHERE id=?')->execute([$fixture['bottle_size']]);
    if (isset($final)) {
        $stmt=$pdo->prepare('SELECT primary_product_id FROM manufacturing_primary_products WHERE final_product_id=?');$stmt->execute([$final]);
        $primaryId=$stmt->fetchColumn();if ($primaryId) $fixture['products'][]=(int)$primaryId;
        $pdo->prepare('DELETE FROM manufacturing_primary_products WHERE final_product_id=?')->execute([$final]);
    }
    foreach ($fixture['products'] as $product) {
        $pdo->prepare('DELETE FROM inventory_stock_logs WHERE product_id=?')->execute([$product]);
        $pdo->prepare('DELETE FROM product_price_logs WHERE product_id=?')->execute([$product]);
        $pdo->prepare('DELETE FROM inventory_products WHERE product_id=?')->execute([$product]);
        $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$product]);
    }
    foreach ($fixture['locations'] as $location) { $pdo->prepare('DELETE FROM inventories WHERE location_id=?')->execute([$location]);$pdo->prepare('DELETE FROM locations WHERE id=?')->execute([$location]); }
    if (isset($fixture['contact'])) $pdo->prepare('DELETE FROM customer_contacts WHERE id=?')->execute([$fixture['contact']]);
    if (isset($fixture['customer'])) $pdo->prepare('DELETE FROM customers WHERE id=?')->execute([$fixture['customer']]);
    if (isset($fixture['user'])) { $pdo->prepare('DELETE FROM activity_logs WHERE user_id=?')->execute([$fixture['user']]);$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$fixture['user']]); }
    if (isset($fixture['session'])) { $session=(session_save_path() ?: sys_get_temp_dir()).'/sess_'.$fixture['session'];if (is_file($session)) unlink($session); }
}
