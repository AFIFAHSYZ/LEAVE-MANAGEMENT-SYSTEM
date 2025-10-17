// sidebar.js
document.addEventListener('DOMContentLoaded', function() {
    // Select all menu items with a dropdown
    const dropdownMenus = document.querySelectorAll('.has-dropdown > a');

    dropdownMenus.forEach(menu => {
        menu.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent default link behavior
            const parentLi = menu.parentElement;

            // Toggle 'active' class to show/hide the dropdown
            parentLi.classList.toggle('active');
        });
    });
});
