/* 
 Created on : 04-Apr-2018, 11:37:37
 Author     : Matt
 */
jQuery(document).ready(function ($) {
    $.urlParam = function (name) {
        var results = new RegExp('[\?&]' + name + '=([^&#]*)').exec(window.location.href);
	if (typeof results != 'undefined' && results) {
		return results[1] || 0;
	}
	return 0;
    }
  
});