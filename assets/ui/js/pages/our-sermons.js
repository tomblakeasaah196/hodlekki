// /assets/ui/js/pages/our-sermons.js
// Our sermons, the members' library (modules/our_sermons/index.php). The page
// is rendered on the server and works without this file: the filters are a
// GET form, the List / Calendar toggle is two submit buttons and the choice is
// remembered by the server (cookie hod_os_view). This only submits the form as
// soon as a filter changes, so nobody has to press Search after picking one.
(function () {
    'use strict';

    HOD.actions.on('os.filter', function (select) {
        var form = select.form;
        if (!form) return;
        if (form.requestSubmit) form.requestSubmit();
        else form.submit();
    });
})();
