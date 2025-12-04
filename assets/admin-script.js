/**
 * GoldenStay Admin Scripts
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Форма авторизации
        $('#goldenstay-login-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $btn = $('#goldenstay-login-btn');
            const $message = $('#goldenstay-login-message');
            
            // Получаем данные формы
            const formData = {
                action: 'goldenstay_login',
                nonce: goldenStayAdmin.nonce,
                email: $('#email').val(),
                password: $('#password').val(),
                api_url: $('#api_url').val()
            };
            
            // Disable button and add loader
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-unlock"></span> Authenticating... <span class="goldenstay-loader"></span>');
            
            // Clear previous messages
            $message.empty();
            
            // Send AJAX request
            $.ajax({
                url: goldenStayAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Successful authentication
                        $message.html(
                            '<div class="goldenstay-notice success">' +
                            '<span class="dashicons dashicons-yes-alt"></span>' +
                            response.data.message +
                            '</div>'
                        );
                        
                        // Reload page after 1 second
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        // Authentication error
                        showError(response.data.message);
                        resetButton();
                    }
                },
                error: function(xhr, status, error) {
                    showError('An error occurred while connecting to the server. Please try again later.');
                    resetButton();
                    console.error('AJAX Error:', error);
                }
            });
            
            function showError(message) {
                $message.html(
                    '<div class="goldenstay-notice error">' +
                    '<span class="dashicons dashicons-warning"></span>' +
                    message +
                    '</div>'
                );
            }
            
            function resetButton() {
                $btn.prop('disabled', false);
                $btn.html('<span class="dashicons dashicons-unlock"></span> Login to Account');
            }
        });
        
        // Logout
        $('#goldenstay-logout-btn').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to logout?')) {
                return;
            }
            
            const $btn = $(this);
            
            // Disable button
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-exit"></span> Logging out... <span class="goldenstay-loader"></span>');
            
            // Send AJAX request
            $.ajax({
                url: goldenStayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'goldenstay_logout',
                    nonce: goldenStayAdmin.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Reload page
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false);
                        $btn.html('<span class="dashicons dashicons-exit"></span> Logout');
                    }
                },
                error: function(xhr, status, error) {
                    alert('An error occurred while connecting to the server.');
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-exit"></span> Logout');
                    console.error('AJAX Error:', error);
                }
            });
        });
        
        // Save API settings (for authenticated users)
        $('#goldenstay-api-settings-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const apiUrl = $('#api_url').val();
            
            // TODO: Add AJAX save functionality
            alert('Settings will be saved. (Under development)');
        });
        
    });

})(jQuery);

