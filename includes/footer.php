        </div> <!-- Closing container div -->

        <!-- Floating Webmail Button -->
        <a href="https://webmail.gamma-vet.com" target="_blank" rel="noopener"
           title="Webmail"
           style="position:fixed;bottom:24px;left:24px;z-index:9999;
                  width:52px;height:52px;border-radius:50%;
                  background:linear-gradient(135deg,#0d6efd,#0a58ca);
                  color:#fff;display:flex;align-items:center;justify-content:center;
                  box-shadow:0 4px 14px rgba(13,110,253,.45);
                  text-decoration:none;transition:transform .2s,box-shadow .2s;"
           onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 20px rgba(13,110,253,.6)'"
           onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 14px rgba(13,110,253,.45)'">
            <i class="fas fa-envelope" style="font-size:1.25rem;"></i>
        </a>

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
                    function isImageLink($link, href) {
                        if ($link.find('img').length) {
                            return true;
                        }

                        if ($link.is('[data-lightbox], [data-fancybox], [data-gallery], .lightbox, .fancybox')) {
                            return true;
                        }

                        return /\.(jpe?g|png|gif|webp|bmp|svg)(\?.*)?$/i.test(href);
                    }

                    function isUnsafeRowActionLink($link, href) {
                        const text = $link.text().trim().toLowerCase();
                        const onclick = ($link.attr('onclick') || '').toLowerCase();

                        if ($link.is('.btn-danger, .btn-outline-danger, .btn-warning, .text-danger, .dropdown-toggle, .delete-item, .remove-item, .js-delete-po, .js-delete-order, .js-delete-payment, [data-delete], [data-action="delete"]')) {
                            return true;
                        }

                        return text === 'delete'
                            || onclick.includes('delete')
                            || /(^|[?&])delete(_[a-z0-9_-]+)?=/.test(href)
                            || /(^|[?&])action=delete(&|$)/.test(href)
                            || /(^|\/|_)delete(_[a-z0-9_-]+)?\.php(\?|$)/.test(href)
                            || /(^|\/)bulk_delete\.php(\?|$)/.test(href);
                    }

                    function isSafeRowNavigationLink() {
                        const $link = jQuery(this);
                        const href = ($link.attr('href') || '').trim();
                        const lowerHref = href.toLowerCase();

                        if (!href || href === '#' || lowerHref.startsWith('javascript:')) {
                            return false;
                        }

                        return !isUnsafeRowActionLink($link, lowerHref) && !isImageLink($link, lowerHref);
                    }

                    jQuery(document).on('click', 'table.table-hover tbody tr', function(e) {
                        // Don't trigger if clicking on an interactive element
                        if (jQuery(e.target).closest('a, button, input, select, textarea, .dropdown-menu, .js-order-preview').length) {
                            return;
                        }

                        const $row = jQuery(this);
                        const $safeLinks = $row.find('a').filter(isSafeRowNavigationLink);

                        // Search for the "View" or "Details" link first
                        let $link = $safeLinks.filter(function() {
                            const text = jQuery(this).text().trim().toLowerCase();
                            const href = (jQuery(this).attr('href') || '').toLowerCase();
                            return text.includes('view') || text.includes('details') || href.includes('view.php') || href.includes('details.php');
                        }).first();

                        // Fallback to first safe link
                        if (!$link.length) {
                            $link = $safeLinks.first();
                        }

                        if ($link.length) {
                            window.location.href = $link.attr('href');
                        }
                    });
                }
            });
        </script>
        <!-- Custom JS -->
        <!-- <script src="<?php echo BASE_URL; ?>assets/js/custom.js"></script> -->
    </body>
</html>
