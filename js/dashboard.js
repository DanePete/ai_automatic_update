(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.aiUpgradeAssistant = {
    attach: function (context, settings) {
      // Initialize terminal output
      const terminal = $('#terminal-output', context);
      if (terminal.length) {
        this.initializeTerminal(terminal);
      }

      // Initialize action buttons
      $('.recommendation-actions .button', context).once('upgrade-assistant').each(function () {
        $(this).on('click', function (e) {
          if ($(this).data('action')) {
            e.preventDefault();
            Drupal.behaviors.aiUpgradeAssistant.handleAction($(this));
          }
        });
      });
    },

    initializeTerminal: function (terminal) {
      // Add auto-scroll functionality
      terminal.scrollTop(terminal[0].scrollHeight);

      // Setup WebSocket connection for real-time updates
      if (window.WebSocket && drupalSettings.aiUpgradeAssistant.websocketUrl) {
        const socket = new WebSocket(drupalSettings.aiUpgradeAssistant.websocketUrl);
        
        socket.onmessage = function (event) {
          const data = JSON.parse(event.data);
          Drupal.behaviors.aiUpgradeAssistant.appendToTerminal(terminal, data.message);
        };

        socket.onerror = function (error) {
          console.error('WebSocket Error:', error);
        };
      }
    },

    appendToTerminal: function (terminal, message) {
      const line = $('<div class="terminal-line"></div>').text(message);
      terminal.append(line);
      terminal.scrollTop(terminal[0].scrollHeight);
    },

    handleAction: function (button) {
      const action = button.data('action');
      const url = Drupal.url('admin/reports/upgrade-assistant/action/' + action);
      
      $.ajax({
        url: url,
        method: 'POST',
        success: function (response) {
          if (response.message) {
            Drupal.behaviors.aiUpgradeAssistant.appendToTerminal(
              $('#terminal-output'),
              response.message
            );
          }
        },
        error: function (xhr, status, error) {
          console.error('Action Error:', error);
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
