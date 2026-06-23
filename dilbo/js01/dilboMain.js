/**
 * dilbo - digital logbook for Rowing and Canoeing
 * https://www.dilbo.org
 * Copyright:  2023-2025  Martin Glade
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 * http://www.apache.org/licenses/LICENSE-2.0
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */

let modal = new Modal()
let dialog = new Dialog()

let config = false
let i18n = I18n.getInstance()

let configTop = false
let cfgPanel = false

let progressUrl = false
let progress = false
let isProgressDisplay = false

let formId = false
let form = false
let formHandler
let inputValidator

function initShort() {
	if (!config) {
		// Start with loading the configuration.
		config = Config.getInstance()
		config.load(initShort)
	}
	else if (!i18n.loaded) {
		// now that the language setting is known, set the locales and load the i18n resource file
		Formatter.setLocale(config.language(), config.timeZoneOffset())
		Parser.setLocale(config.language(), config.timeZoneOffset())
		i18n = I18n.getInstance()
		i18n.loadResource(config.language().toLowerCase(), initShort)
	}
	else if (isProgressDisplay)
		modal.showProgress()
	else
		init()
}


function init() {
	if (!config)
		initShort(init)
	else if (!cfgPanel && configTop) {
		// initialise the configuration editor panel if this is a configuration edit
		cfgPanel = new ConfigPanel(config, modal, configTop.split("|")[0], configTop.split("|")[1])
		cfgPanel.refresh()
		form = new Form(Ids.generateUid(6))
		formHandler = new FormHandler()
		inputValidator = new InputValidator()
		init()
	}
	else if (!form && formId) {
		// initialise the form if this is a server form
		let formDefinition = Codec.htmlSpecialCharsDecode($("#formDefinition-" + formId).html())
		let fsId = formId.split("-")[0]
		let recordItem = config.getItem(".tables." + formId.split("-")[1])
		form = new Form(fsId)
		formHandler = new FormHandler()
		inputValidator = new InputValidator()
		form.init(recordItem, formDefinition)
		form.parseProvided()
		form.setAutocomplete()
		form.setSpecialInputTrigger()
	}
}

// configuration manager
$(document).ready(function() {
	// window.localStorage.clear()
	// find the tfyhClass and id indicating what sort of activity to trigger
	let sessionUserCsv = $('#session_user').html()
	if (sessionUserCsv)
		User.getInstance().set(sessionUserCsv)
	let configElement = $(".tfyhConfigTop")
	configTop = $(configElement).attr("id")
	let formElement = $(".tfyhForm")
	formId = $(formElement).attr("id")
	let progressElement = $(".tfyhProgressUrl")
	progressUrl = $(progressElement).attr("id")
	// trigger the activity according to its priority
	// progress control is first priority
	if (progressUrl) {
		modal.setProgressParameters(progressUrl, 500)
		isProgressDisplay = true
		// shorthand loader for this case
		initShort(modal.showProgress)
	}
	// form editing or configuration editing the third
	else if (formId || configTop)
		new SettingsLoader(init); // this appends all initialisation actions to the SettingsLoader.
});

/**
 * Toggle the sidebar menu sub-options
 *
 * @param idSuffix
 * @returns
 */
function openSubMenu(idSuffix) {
	let submenu_items = document.getElementsByClassName("subMenu" + idSuffix);
	for (let i = 0; i < submenu_items.length; i++) {
		if (submenu_items[i].className.indexOf("w3-show") === -1) {
			submenu_items[i].className += " w3-show";
		} else {
			submenu_items[i].className = submenu_items[i].className.replace(
				" w3-show", "");
		}
	}
}

// Open and close sidebar
function w3_open() {
	document.getElementById("menuSidebar").style.display = "block";
	document.getElementById("menuOverlay").style.display = "block";
}

function w3_close() {
	document.getElementById("menuSidebar").style.display = "none";
	document.getElementById("menuOverlay").style.display = "none";
}

