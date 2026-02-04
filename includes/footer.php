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
            });
        </script>
        <!-- Custom JS -->
        <!-- <script src="<?php echo BASE_URL; ?>assets/js/custom.js"></script> -->
    </body>
</html>
