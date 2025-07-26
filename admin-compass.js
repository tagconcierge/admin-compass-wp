jQuery(document).ready(function($) {
    var $overlay = $("#admin-compass-overlay");
    var $modal = $("#admin-compass-modal");
    var $input = $("#admin-compass-input");
    var $results = $("#admin-compass-results");
    var currentFocus = -1;
    var currentRequest = null;
    var searchTimer = null;
    var searchCache = {};
    var recentItems = JSON.parse(localStorage.getItem('adminCompassRecent') || '[]');
    var indexingCheckInterval = null;

    $(".admin-compass-icon").on("click", function(e) {
        e.preventDefault();
        toggleModal();
    });

    $(document).on("keydown", function(e) {
        // Ctrl+K or Cmd+K
        if ((e.ctrlKey || e.metaKey) && e.which === 75) {
            e.preventDefault();
            toggleModal();
        }
        // Also keep Ctrl+Shift+F for backwards compatibility
        else if (e.ctrlKey && e.shiftKey && e.which === 70) {
            e.preventDefault();
            toggleModal();
        }
    });

    $(document).on("keyup", function(e) {
        if (e.key === "Escape") {
            closeModal();
        }
    });

    // Close modal when clicking outside
    $overlay.on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Prevent clicks inside the modal from closing it
    $modal.on('click', function(e) {
        e.stopPropagation();
    });

    function toggleModal() {
        $overlay.toggleClass('admin-compass-hidden');
        $modal.toggleClass('admin-compass-hidden');
        if (!$modal.hasClass('admin-compass-hidden')) {
            $input.focus();
            showRecentItems();
            checkIndexingStatus();
            // Check indexing status periodically while modal is open
            indexingCheckInterval = setInterval(checkIndexingStatus, 5000);
        } else {
            // Clear interval when modal is closed
            if (indexingCheckInterval) {
                clearInterval(indexingCheckInterval);
                indexingCheckInterval = null;
            }
        }
    }

    function closeModal() {
        $overlay.addClass('admin-compass-hidden');
        $modal.addClass('admin-compass-hidden');
        $input.val("");
        $results.empty();
        currentFocus = -1;
        // Clear interval when modal is closed
        if (indexingCheckInterval) {
            clearInterval(indexingCheckInterval);
            indexingCheckInterval = null;
        }
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
            showRecentItems();
            return;
        }

        searchTimer = setTimeout(function() {
            showLoading();
            performSearch(query);
        }, 150);
    });

    function checkIndexingStatus() {
        $.ajax({
            url: adminCompass.ajaxurl,
            type: 'POST',
            data: {
                action: 'admin_compass_check_indexing',
                nonce: adminCompass.nonce
            },
            success: function(response) {
                if (response.success && response.data.is_indexing) {
                    showIndexingNotice(response.data.elapsed_time);
                } else {
                    hideIndexingNotice();
                }
            }
        });
    }
    
    function showIndexingNotice(elapsedTime) {
        var minutes = Math.floor(elapsedTime / 60);
        var seconds = elapsedTime % 60;
        var timeText = minutes > 0 ? minutes + 'm ' + seconds + 's' : seconds + 's';
        
        var $notice = $('#admin-compass-indexing-notice');
        if ($notice.length === 0) {
            $notice = $('<div id="admin-compass-indexing-notice" class="admin-compass-indexing-notice">' +
                '<span class="spinner"></span>' +
                '<span class="text">Search index is being rebuilt... (' + timeText + ')</span>' +
                '<div class="subtext">Search results may be incomplete</div>' +
                '</div>');
            $('.admin-compass-container').prepend($notice);
        } else {
            $notice.find('.text').text('Search index is being rebuilt... (' + timeText + ')');
        }
    }
    
    function hideIndexingNotice() {
        $('#admin-compass-indexing-notice').remove();
    }
    
    function performSearch(query) {
        // Check cache first
        if (searchCache[query]) {
            displayResults(searchCache[query]);
            currentFocus = -1;
            return;
        }

        currentRequest = $.ajax({
            url: adminCompass.ajaxurl,
            method: "POST",
            data: {
                action: "admin_compass_search",
                nonce: adminCompass.nonce,
                query: query
            },
            success: function(response) {
                if (response.success) {
                    // Cache the results
                    searchCache[query] = response.data;
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
        $items.eq(currentFocus).get(0).scrollIntoView({ block: 'nearest' });
    }

    function removeActive($items) {
        $items.removeClass("active");
    }

    function displayResults(items) {
        $results.empty();
        if (items.length === 0) {
            $results.html("<div class='no-results'>No results found</div>");
            return;
        }

        items.forEach(function(item, index) {
            // Get content type icon
            var icon = getContentTypeIcon(item.type);

            var quickActions = $("<div class='quick-actions'>");

            // Always show edit/navigate button
            quickActions.append($("<button class='quick-action edit' title='Edit'>✏️</button>")
                .on("click", function(e) {
                    e.stopPropagation();
                    addToRecent(item);
                    showNavigationLoading(item.title);
                    window.location.href = item.edit_url;
                })
            );

            // Only show view button for content that can be viewed (not settings or orders)
            if (item.type !== 'settings' && item.type !== 'shop_order') {
                quickActions.append($("<button class='quick-action view' title='View'>👁️</button>")
                    .on("click", function(e) {
                        e.stopPropagation();
                        var viewUrl = getViewUrl(item);
                        if (viewUrl) {
                            window.open(viewUrl, '_blank');
                        }
                    })
                );
            }

            var resultItem = $("<div class='result-item'>")
                .append($("<span class='result-icon'>").html(icon))
                .append($("<div class='result-content'>")
                    .append($("<div class='result-title'>").text(item.title))
                    .append($("<div class='result-preview'>").text(item.preview || ''))
                )
                .append($("<span class='result-type'>").text(formatContentType(item.type)))
                .append(quickActions)
                .on("click", function() {
                    addToRecent(item);
                    showNavigationLoading(item.title);
                    window.location.href = item.edit_url;
                })
                .on("mouseover", function() {
                    currentFocus = index;
                    addActive($results.find(".result-item"));
                });

            $results.append(resultItem);
        });
    }

    function getContentTypeIcon(type) {
        var icons = {
            'post': '📝',
            'page': '📄',
            'product': '🛒',
            'attachment': '📎',
            'shop_order': '🛍️',
            'settings': '⚙️'
        };
        return icons[type] || '📄';
    }

    function formatContentType(type) {
        var types = {
            'post': 'Post',
            'page': 'Page',
            'product': 'Product',
            'attachment': 'Media',
            'shop_order': 'Order',
            'settings': 'Settings'
        };
        return types[type] || type.charAt(0).toUpperCase() + type.slice(1);
    }

    function showLoading() {
        $results.html("<div class='loading-indicator'>🔍 Searching...</div>");
    }

    function getViewUrl(item) {
        // This is simplified - in a real implementation, you'd need proper post URLs
        if (item.type === 'settings') {
            return null; // Settings pages don't have view URLs
        }
        // For now, just return the site URL + post ID for posts/pages
        return window.location.origin + '/?p=' + item.id;
    }

    function addToRecent(item) {
        // Remove if already exists
        recentItems = recentItems.filter(function(recent) {
            return recent.id !== item.id || recent.type !== item.type;
        });

        // Add to beginning
        recentItems.unshift(item);

        // Keep only last 10 items
        recentItems = recentItems.slice(0, 10);

        // Save to localStorage
        localStorage.setItem('adminCompassRecent', JSON.stringify(recentItems));
    }

    function showRecentItems() {
        if (recentItems.length === 0) {
            $results.html("<div class='no-results'>Start typing to search...</div>");
            return;
        }

        $results.html("<div class='recent-header'>Recent Items</div>");
        displayResults(recentItems.slice(0, 5)); // Show only 5 most recent
    }

    function showNavigationLoading(title, action) {
        action = action || 'Loading...';

        // Replace the entire modal content with loading state
        $results.html("<div class='navigation-loading'>" +
            "<div class='loading-spinner'>⏳</div>" +
            "<div class='loading-title'>" + action + "</div>" +
            "<div class='loading-subtitle'>\"" + title + "\"</div>" +
        "</div>");

        // Hide the input
        $input.prop('disabled', true).css('opacity', '0.5');

        // Optional: Add slight delay to show the loading state
        setTimeout(function() {
            // This gives users visual feedback before page navigation
        }, 100);
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