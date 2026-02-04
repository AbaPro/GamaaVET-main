from pathlib import Path
path = Path('portal/customer_portal.php')
text = path.read_text(encoding='utf-8')
start = text.index("    <div class=\"max-w-6xl mx-auto space-y-6\">")
end = text.rindex("</body>")
new_section = '''    <div class="max-w-6xl mx-auto space-y-6">
        <div class="bg-gradient-to-r from-cyan-600 to-blue-700 rounded-3xl text-white p-8 shadow-2xl">
            <h1 class="text-3xl font-bold mb-3">مرحباً <?= htmlspecialchars($customer['name']); ?></h1>
            <p class="text-cyan-100 mb-6">اطلع على نظرة شاملة لأحدث الطلبات وأرصدة الحساب في لحظات.</p>
            <div class="grid gap-5 md:grid-cols-3">
                <div class="bg-white/10 rounded-2xl p-5 backdrop-blur">
                    <p class="text-sm text-cyan-100 mb-2">إجمالي المستحقات</p>
                    <p class="text-3xl font-semibold"><?= number_format($dueAmount, 2); ?> EGP</p>
                </div>
                <div class="bg-white/10 rounded-2xl p-5 backdrop-blur">
                    <p class="text-sm text-cyan-100 mb-2">رصيد المحفظة</p>
                    <p class="text-3xl font-semibold"><?= number_format($customer['wallet_balance'], 2); ?> EGP</p>
                </div>
                <div class="bg-white/10 rounded-2xl p-5 backdrop-blur">
                    <p class="text-sm text-cyan-100 mb-2">آخر طلب</p>
                    <p class="text-3xl font-semibold"><?= !empty($orders) ? date('Y-m-d', strtotime($orders[0]['order_date'])) : 'لا توجد بيانات'; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-lg p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xl">
                    <i class="bx bx-buildings text-2xl"></i>
                </div>
                <h2 class="text-xl font-semibold text-slate-800">بيانات المصنع</h2>
            </div>
            <?php if (!empty($customer['factory_name'])): ?>
                <div class="grid gap-4 sm:grid-cols-3">
                    <p><span class="text-slate-500">اسم المصنع:</span> <span class="font-semibold text-slate-800"><?= htmlspecialchars($customer['factory_name']); ?></span></p>
                    <?php if (!empty($customer['contact_person'])): ?>
                        <p><span class="text-slate-500">الشخص المسؤول:</span> <span class="font-semibold text-slate-800"><?= htmlspecialchars($customer['contact_person']); ?></span></p>
                    <?php endif; ?>
                    <?php if (!empty($customer['contact_phone'])): ?>
                        <p><span class="text-slate-500">رقم التواصل:</span> <span class="font-semibold text-slate-800"><?= htmlspecialchars($customer['contact_phone']); ?></span></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="text-slate-500">لا توجد بيانات مصنع مرتبطة بهذا الحساب.</p>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-2xl shadow-lg p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xl">
                    <i class="bx bx-receipt text-2xl"></i>
                </div>
                <h2 class="text-xl font-semibold text-slate-800">أحدث الطلبات</h2>
            </div>
            <?php if ($orders): ?>
                <div class="space-y-4">
                    <?php foreach ($orders as $index => $order): ?>
                        <?php
                            $orderItems = $orderItemsByOrder[$order['id']] ?? [];
                            $shippingAmount = $order['shipping_cost_type'] === 'manual' ? (float)$order['shipping_cost'] : 0;
                            $orderDue = max(0, (float)$order['total_amount'] - (float)$order['paid_amount']);
                            $statusClass = $statusBadgeMap[$order['status']] ?? 'bg-slate-400';
                            $statusLabel = ucwords(str_replace('-', ' ', $order['status']));
                            $discountLabel = $discountBasisMap[$order['discount_basis']] ?? ucwords(str_replace('-', ' ', $order['discount_basis']));
                        ?>
                        <details class="bg-white rounded-3xl shadow-sm overflow-hidden" <?= $index === 0 ? 'open' : ''; ?>>
                            <summary class="flex items-center justify-between gap-4 px-6 py-4 cursor-pointer select-none">
                                <div>
                                    <p class="text-base font-semibold text-slate-800"><?= htmlspecialchars($order['internal_id']); ?></p>
                                    <p class="text-xs text-slate-500"><?= date('Y-m-d', strtotime($order['order_date'])); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-bold text-slate-900"><?= number_format($order['total_amount'], 2); ?> EGP</p>
                                    <p class="text-xs text-slate-500">المدفوع: <?= number_format($order['paid_amount'], 2); ?> • المتبقي: <?= number_format($orderDue, 2); ?></p>
                                </div>
                            </summary>
                            <div class="px-6 pb-6 space-y-4 text-sm text-slate-600">
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-white text-xs <?= $statusClass; ?>">
                                        <?= htmlspecialchars($statusLabel); ?>
                                    </span>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-slate-100 text-slate-700 text-xs">
                                        نوع الخصم: <?= htmlspecialchars($discountLabel); ?> - <?= number_format($order['discount_amount'], 2); ?> EGP
                                    </span>
                                    <?php if ($shippingAmount > 0): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full bg-slate-100 text-slate-700 text-xs">
                                            الشحن: <?= number_format($shippingAmount, 2); ?> EGP
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full bg-slate-50 text-slate-500 text-xs">
                                            لا يوجد شحن مضاف
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <p class="text-slate-500 text-xs mb-1">إجمالي الطلب</p>
                                        <p class="font-semibold text-slate-900"><?= number_format($order['total_amount'], 2); ?> EGP</p>
                                    </div>
                                    <div>
                                        <p class="text-slate-500 text-xs mb-1">المدفوع</p>
                                        <p class="font-semibold text-emerald-600"><?= number_format($order['paid_amount'], 2); ?> EGP</p>
                                    </div>
                                    <div>
                                        <p class="text-slate-500 text-xs mb-1">المتبقي</p>
                                        <p class="font-semibold text-rose-600"><?= number_format($orderDue, 2); ?> EGP</p>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-slate-800 mb-2">تفاصيل المنتجات</h4>
                                    <?php if ($orderItems): ?>
                                        <div class="overflow-x-auto rounded-2xl border border-slate-100">
                                            <table class="min-w-full text-sm">
                                                <thead class="bg-slate-50 text-slate-500">
                                                    <tr>
                                                        <th class="px-4 py-2 text-right">المنتج</th>
                                                        <th class="px-4 py-2 text-center">الكمية</th>
                                                        <th class="px-4 py-2 text-center">سعر الوحدة</th>
                                                        <th class="px-4 py-2 text-center">الإجمالي</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($orderItems as $line): ?>
                                                        <tr class="border-t border-slate-100 <?= $line['is_free_sample'] ? 'bg-amber-50' : ''; }>">
                                                            <td class="px-4 py-2 font-medium text-slate-800">
                                                                <?= htmlspecialchars($line['product_name']); ?>
                                                                <?php if ($line['is_free_sample']): ?>
                                                                    <span class="text-xs text-amber-600 ml-2">عينة مجانية</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="px-4 py-2 text-center"><?= (int)$line['quantity']; ?></td>
                                                            <td class="px-4 py-2 text-center"><?= number_format($line['unit_price'], 2); ?></td>
                                                            <td class="px-4 py-2 text-center"><?= number_format($line['total_price'], 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-slate-500 text-sm">لا توجد منتجات مسجلة لهذا الطلب.</p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($order['notes'])): ?>
                                    <div class="bg-slate-50 rounded-2xl p-4 text-slate-600">
                                        <p class="font-semibold text-slate-800 mb-1">ملاحظات</p>
                                        <p><?= nl2br(htmlspecialchars($order['notes'])); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-slate-500">لا توجد طلبات متاحة حالياً.</p>
            <?php endif; ?>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="bg-white rounded-2xl shadow-lg p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl">
                        <i class="bx bx-wallet text-2xl"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-slate-800">حركة المحفظة</h2>
                </div>
                <?php if ($walletMoves): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th class="px-4 py-3 text-right">التاريخ</th>
                                    <th class="px-4 py-3 text-right">النوع</th>
                                    <th class="px-4 py-3 text-right">المبلغ</th>
                                    <th class="px-4 py-3 text-right">الملاحظات</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($walletMoves as $move): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3"><?= date('Y-m-d', strtotime($move['created_at'])); ?></td>
                                        <td class="px-4 py-3 capitalize"><?= htmlspecialchars($move['type']); ?></td>
                                        <td class="px-4 py-3 font-semibold text-slate-800"><?= number_format($move['amount'], 2); ?> EGP</td>
                                        <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($move['notes']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-slate-500">لا توجد حركات مسجلة حتى الآن.</p>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xl">
                        <i class="bx bx-package text-2xl"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-slate-800">كميات المخزون</h2>
                </div>
                <?php if ($inventoryLines): ?>
                    <div class="divide-y divide-slate-100 text-sm">
                        <?php foreach ($inventoryLines as $line): ?>
                            <div class="flex items-center justify-between py-3">
                                <span class="text-slate-700"><?= htmlspecialchars($line['name']); ?></span>
                                <span class="font-bold text-slate-900"><?= number_format($line['qty']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-slate-500">لا تتوفر بيانات مخزون حالياً.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
'''
text = text[:start] + new_section + text[end:]
text += text[end:][:0]
text = text.rstrip() + "\n</body>\n</html>\n"
path.write_text(text, encoding='utf-8')
