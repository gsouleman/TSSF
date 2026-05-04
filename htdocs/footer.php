</div><!-- /.main-wrapper -->
<div class="sidebar-overlay" data-reff=""></div>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js" ></script>
    <!-- <script src="assets/js/jquery-3.2.1.min.js"></script> -->
	<script src="assets/js/popper.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.bootstrap4.min.js"></script>
    <script src="assets/js/jquery.slimscroll.js"></script>
    <script src="assets/js/Chart.bundle.js"></script>
    <script src="assets/js/chart.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/hms-shell-animations.js"></script>
    <script src="assets/js/select2.min.js"></script>
    <script src="assets/js/moment.min.js"></script>
    <script src="assets/js/bootstrap-datetimepicker.min.js"></script>
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    

<script>
            $(function () {
                if (typeof $.fn.datetimepicker !== 'function') {
                    return;
                }
                if ($('#datetimepicker3').length) {
                    $('#datetimepicker3').datetimepicker({ format: 'LT' });
                }
                if ($('#datetimepicker4').length) {
                    $('#datetimepicker4').datetimepicker({ format: 'LT' });
                }
            });
     </script>

<?php if (isset($extra_footer_html)) echo $extra_footer_html; ?>
</body>
</html>