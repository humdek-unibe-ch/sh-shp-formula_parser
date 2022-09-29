const FORMULA_SCHEMA_URL = "/server/plugins/formulaParser/schemas/formulaConfig/formulaConfig.json";
var formulaConfigInitCalls = {};
const formulaConfigInit = 'formulaConfigInit'

$(document).ready(function () {
    initFormulaConfigBuilder();
});

function initFormulaConfigBuilder() {
    var formulaConfigBuilder = $('.formulaConfigBuilder')[0];
    if ($(formulaConfigBuilder).data(formulaConfigInit)) {
        // already initialized do not do it again
        return;
    }
    $(formulaConfigBuilder).data(formulaConfigInit, true);
    $('.formulaConfigBuilderMonaco').each(function () {
        // load the monaco editor for json fields        
        require.config({ paths: { vs: BASE_PATH + '/js/ext/vs' } });
        var json = $(this)[0];

        require(['vs/editor/editor.main'], function () {
            var editorOptions = {
                value: $(json).prev().val(),
                language: 'json',
                automaticLayout: true,
                renderLineHighlight: "none"
            }
            var editorFormulaConfig = monaco.editor.create(json, editorOptions);
            editorFormulaConfig.getAction('editor.action.formatDocument').run().then(() => {
                calcMonacoEditorSize(editorFormulaConfig, json);
            });
            editorFormulaConfig.onDidChangeModelContent(function (e) {
                $(json).prev().val(editorFormulaConfig.getValue());
                calcMonacoEditorSize(editorFormulaConfig, json);
                $(json).prev().trigger('change');
            });
            showFormulaConfigBuilder(json, editorFormulaConfig);
        });
    })
}

// ********************************************* FORMULA CONFIG BUILDER *****************************************

// show the Formula config builder
// on click the modal is loaded and show the builder
// on change it updates the monaco editor and the monaco editor updates the input fields
function showFormulaConfigBuilder(json, monacoEditor) {
    var editor;
    var defValue = getFormulaConfigJson(json);
    $('.formulaConfigBuilderBtn').each(function () {
        $(this).off('click').click(() => {
            $(".formulaConfig_builder_modal_holder").modal({
                backdrop: false
            });
            if (editor) {
                // set the latest value if the user changed the JSON manually                
                editor.setValue(getFormulaConfigJson(json));
            }
            $('.saveFormulaConfigBuilder').each(function () {
                $(this).attr('data-dismiss', 'modal');
                // on modal close set the value to the Monaco editor
                $(this).click(function () {
                    monacoEditor.getModel().setValue(JSON.stringify(editor.getValue(), null, 3));
                })
            });
        });
    });
    var schemaUrl = window.location.protocol + "//" + window.location.host + BASE_PATH + FORMULA_SCHEMA_URL;
    // get the schema with AJAX call
    $.ajax({
        dataType: "json",
        url: schemaUrl,
        success: (s) => {
            editor = new JSONEditor($('.formulaConfig_builder')[0], {
                theme: 'bootstrap4',
                iconlib: 'fontawesome5',
                ajax: true,
                schema: s,
                startval: defValue,
                show_errors: "always",
                display_required_only: true
            });
        }
    });
}

function getFormulaConfigJson(json) {
    try {
        var res = JSON.parse($(json).prev().val());
        return res;
    } catch (error) {
        return null;
    }
}

// ********************************************* FORMULA SURVEY CONFIG BUILDER *****************************************