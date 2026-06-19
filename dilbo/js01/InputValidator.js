/**
 * Validate the input into a form field upon its change.
 */

class InputValidator {

	/**
	 * Build all tables and load them from local storage
	 */
	constructor() {
	}

	handleValue(input, formInputField) {
		// currently nothing to do.
	}
	/**
	 * Validate the entered data beyond the syntactical checks
	 */
	validateEntry(input, formInputField) {
		// check the period, based validity and add the red-amber-green input field's sidebar.
		// empty values are never checked
		if (input.value.length === 0) {
			$(input).removeClass("uuid-valid")
					.removeClass("uuid-invalid")
					.removeClass("uuid-not-found")					
					.addClass("uuid-not-checked");
			return;
		}
		// get the record and format, according to the validation result.
		let invalidFrom = formInputField["options"][input.value] ?? false;
		if (invalidFrom === false)
			$(input).removeClass("uuid-valid")
					.removeClass("uuid-invalid")
					.addClass("uuid-not-found")
					.removeClass("uuid-not-checked");
		else if (invalidFrom < Math.floor(Date.now() / 1000))
			$(input).removeClass("uuid-valid")
					.addClass("uuid-invalid")
					.removeClass("uuid-not-found")					
					.removeClass("uuid-not-checked");
		else
			$(input).addClass("uuid-valid")
					.removeClass("uuid-invalid")
					.removeClass("uuid-not-found")
					.removeClass("uuid-not-checked");

	}
}