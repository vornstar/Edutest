(function () {
    var dropdowns = document.querySelectorAll('.nav-dropdown');

    dropdowns.forEach(function (dropdown) {
        dropdown.addEventListener('toggle', function () {
            if (!dropdown.open) {
                return;
            }
            dropdowns.forEach(function (other) {
                if (other !== dropdown) {
                    other.open = false;
                }
            });
        });
    });

    document.addEventListener('click', function (event) {
        dropdowns.forEach(function (dropdown) {
            if (dropdown.open && !dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        });
    });
})();
