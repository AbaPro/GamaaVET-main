        </div> <!-- Closing container div -->

        <footer class="mt-5 py-3 bg-light">
            <div class="container">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-0">&copy; <?php echo date('Y'); ?> Inventory System. All rights reserved.</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <p class="mb-0">Version 1.0.0</p>
                    </div>
                </div>
            </div>
        </footer>

        <!-- Bootstrap 5 JS Bundle with Popper -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (window.jQuery && typeof jQuery.fn.DataTable !== 'undefined') {
                    jQuery('.js-datatable').each(function() {
                        if (!jQuery.fn.dataTable.isDataTable(this)) {
                            jQuery(this).DataTable({
                                pageLength: 25,
                                lengthMenu: [10, 25, 50, 100],
                                order: []
                            });
                        }
                    });
                }

                // Global table row click logic
                if (window.jQuery) {
                    jQuery(document).on('click', 'table.table-hover tbody tr', function(e) {
                        // Don't trigger if clicking on an interactive element
                        if (jQuery(e.target).closest('a, button, input, select, textarea, .dropdown-menu, .js-order-preview').length) {
                            return;
                        }

                        const $row = jQuery(this);
                        // Search for the "View" or "Details" link first
                        let $link = $row.find('a').filter(function() {
                            const text = jQuery(this).text().trim().toLowerCase();
                            const href = (jQuery(this).attr('href') || '').toLowerCase();
                            return text.includes('view') || text.includes('details') || href.includes('view.php') || href.includes('details.php');
                        }).first();

                        // Fallback to first non-destructive link
                        if (!$link.length) {
                            $link = $row.find('a:not(.btn-danger, .btn-warning, .dropdown-toggle, .delete-item, .remove-item)').first();
                        }

                        if ($link.length) {
                            const href = $link.attr('href');
                            if (href && href !== '#' && !href.startsWith('javascript:')) {
                                window.location.href = href;
                            }
                        }
                    });
                }
            });
        </script>
        <!-- Custom JS -->
        <!-- <script src="<?php echo BASE_URL; ?>assets/js/custom.js"></script> -->
    </body>
</html>
