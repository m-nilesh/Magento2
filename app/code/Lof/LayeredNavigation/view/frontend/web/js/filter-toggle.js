require(['jquery'], function($) {
    $(document).ready(function() {
        function bindFilterToggle() {
            var $filterButton = $('#filter-toggle-button');
            var $filtersContent = $('#filter-content');

            $filtersContent.hide();
            
            // Only bind the click event once
            $filterButton.off('click').on('click', function() {
                var expanded = $filterButton.attr('aria-expanded') === 'true';
                $filterButton.attr('aria-expanded', !expanded);
                $filtersContent.toggle();  // Toggle visibility
            });
        }

        // Bind the filter toggle on initial load
        bindFilterToggle();

        // Bind the filter toggle on AJAX content update
        $(document).on('navigationUpdated', function() {
            bindFilterToggle();
        });
    });
});
