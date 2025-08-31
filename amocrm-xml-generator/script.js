define(['jquery'], function($) {
  var CustomWidget = function () {
    var self = this;

    this.callbacks = {
      render: function() {
        if (self.system().area === 'lcard') {
            const widgetCode = self.get_settings().widget_code;
            var button_text = self.i18n('actions.create_xml');
            var template = `
                <style>
                    .card-widgets__widget[data-widget-code="${widgetCode}"] .card-widgets__widget__caption,
                    .card-widgets__widget[data-widget-code="${widgetCode}"] .card-widgets__widget__body {
                        background-color: #015EA5 !important;
                        color: white !important;
                    }
                    .card-widgets__widget[data-widget-code="${widgetCode}"] .card-widgets__widget__caption .card-widgets__widget-title__text {
                        color: white !important;
                    }
                    #create_xml_button {
                        background-color: #FFC107;
                        border: 1px solid #FFC107;
                        color: #333;
                        padding: 10px 20px;
                        text-align: center;
                        text-decoration: none;
                        display: inline-block;
                        font-size: 14px;
                        margin: 4px 2px;
                        cursor: pointer;
                        border-radius: 4px;
                        width: 100%;
                        box-sizing: border-box;
                        font-weight: bold;
                        transition: background-color 0.3s, border-color 0.3s;
                    }
                    #create_xml_button:hover {
                        background-color: #ffb300;
                        border-color: #ffb300;
                    }
                </style>
                <div class="button-input-wrapper" style="margin-bottom: 10px;">
                    <button id="create_xml_button">${button_text}</button>
                </div>`;
            self.render_template({
                caption: {
                    class_name: 'js-ac-caption'
                },
                body: '',
                render: template
            }, {});
        }
        return true;
      },
      init: function() {
        return true;
      },
      bind_actions: function() {
        if (self.system().area === 'lcard') {
            $('#create_xml_button').on('click', function() {
                const settings = self.get_settings();
                const bearerToken = settings.bearer_token;
                const leadData = AMOCRM.data.current_card;
                const leadId = leadData.id;
                const leadName = leadData.name || 'lead_' + leadId;

                if (!leadId) {
                    alert(self.i18n('messages.no_lead_id'));
                    return;
                }

                if (!bearerToken) {
                    alert(self.i18n('messages.no_bearer_token'));
                    return;
                }

                const xhr = new XMLHttpRequest();
                xhr.open('GET', `https://api-avto-terminal.ru/api/amocrm/lead/${leadId}/xml`, true);
                xhr.setRequestHeader('Authorization', `Bearer ${bearerToken}`);
                xhr.responseType = 'blob';

                xhr.onload = function() {
                    if (this.status === 200) {
                        const blob = this.response;
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = url;
                        a.download = `${leadName}.xml`;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                    } else {
                        const reader = new FileReader();
                        reader.onload = function() {
                            const errorText = reader.result;
                            console.error('Server error:', errorText);
                            alert(self.i18n('messages.xml_creation_error') + '\n' + errorText);
                        };
                        reader.readAsText(this.response);
                    }
                };

                xhr.onerror = function() {
                    console.error('Request failed');
                    alert(self.i18n('messages.xml_creation_error'));
                };

                xhr.send();
            });
        }
        return true;
      },
      onSave: function() {
        return true;
      },
      destroy: function() {}
    };

    return this;
  };

  return CustomWidget;
});
