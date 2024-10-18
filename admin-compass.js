jQuery(document).ready(function($) {
    var $modal = $("#admin-compass-modal");
    var $input = $("#admin-compass-input");
    var $results = $("#admin-compass-results");
    var currentFocus = -1;
    var currentRequest = null;
    var searchTimer = null;

    $(".admin-compass-icon").on("click", function(e) {
        e.preventDefault();
        toggleModal();
    });

    $(document).on("keydown", function(e) {
        if (e.ctrlKey && e.shiftKey && e.which === 70) {
            e.preventDefault();
            toggleModal();
        }
    });

    $(document).on("keyup", function(e) {
        if (e.key === "Escape") {
            closeModal();
        }
    });

    function toggleModal() {
        $modal.toggle();
        if ($modal.is(":visible")) {
            $input.focus();
        }
    }

    function closeModal() {
        $modal.hide();
        $input.val("");
        $results.empty();
        currentFocus = -1;
    }

    $input.on("input", function() {
        var query = $(this).val();

        if (searchTimer) {
            clearTimeout(searchTimer);
        }

        if (currentRequest) {
            currentRequest.abort();
        }

        if (query.length < 2) {
            $results.empty();
            return;
        }

        searchTimer = setTimeout(function() {
            performSearch(query);
        }, 300);
    });

    function performSearch(query) {
        currentRequest = $.ajax({
            url: adminCompass.ajaxurl,
            method: "POST",
            data: {
                action: "admin_compass_search",
                nonce: adminCompass.nonce,
                query: query
            },
            beforeSend: function() {
                if (currentRequest != null) {
                    currentRequest.abort();
                }
            },
            success: function(response) {
                if (response.success) {
                    displayResults(response.data);
                    currentFocus = -1;
                }
            },
            complete: function() {
                currentRequest = null;
            }
        });
    }

    $input.on("keydown", function(e) {
        var $items = $results.find(".result-item");
        if (!$items.length) return;

        if (e.which === 40) { // Arrow down
            currentFocus++;
            addActive($items);
        } else if (e.which === 38) { // Arrow up
            currentFocus--;
            addActive($items);
        } else if (e.which === 13) { // Enter
            e.preventDefault();
            if (currentFocus > -1) {
                $items.eq(currentFocus).click();
            }
        }
    });

    function addActive($items) {
        if (!$items) return false;
        removeActive($items);
        if (currentFocus >= $items.length) currentFocus = 0;
        if (currentFocus < 0) currentFocus = ($items.length - 1);
        $items.eq(currentFocus).addClass("active");
        scrollResultIntoView($items.eq(currentFocus));
    }

    function removeActive($items) {
        $items.removeClass("active");
    }

    function scrollResultIntoView($item) {
        var container = $results[0];
        var item = $item[0];
        if (item.offsetTop < container.scrollTop) {
            container.scrollTop = item.offsetTop;
        } else {
            const offsetBottom = item.offsetTop + item.offsetHeight;
            const scrollBottom = container.scrollTop + container.offsetHeight;
            if (offsetBottom > scrollBottom) {
                container.scrollTop = offsetBottom - container.offsetHeight;
            }
        }
    }

    function displayResults(items) {
        $results.empty();
        if (items.length === 0) {
            $results.html("<div class='no-results'>No results found</div>");
            return;
        }

        items.forEach(function(item, index) {
            var icon = getItemIcon(item.type);
            $results.append(
                $("<div class='result-item'>")
                    .append($("<span class='result-icon'>" + icon + "</span>"))
                    .append($("<span class='result-title'>" + item.title + "</span>"))
                    .append($("<span class='result-type'>" + item.type + "</span>"))
                    .on("click", function() {
                        window.location.href = item.edit_url;
                    })
                    .on("mouseover", function() {
                        currentFocus = index;
                        addActive($results.find(".result-item"));
                    })
            );
        });
    }

    function getItemIcon(type) {
        var icons = {
            "post": "📄",
            "page": "📑",
            "product": "🛍️",
            "order": "📦",
            "admin_page": "⚙️",
            "default": "🔍"
        };
        return icons[type] || icons["default"];
    }
});
jQuery(document).ready(function($) {
    $('.schedule-background-job').on('click', function(e) {
        e.preventDefault();

        $.ajax({
            url: adminCompass.ajaxurl,
            method: "POST",
            data: {
                action: "admin_compass_reindex",
                nonce: adminCompass.nonce
            },
            success: function(response) {
                alert('Background job scheduled successfully!');
            },
            error: function() {
                alert('There was an error scheduling the background job.');
            }
        });
    });
});