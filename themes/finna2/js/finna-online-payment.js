/*global VuFind, finna */
finna.onlinePayment = (function finnaOnlinePayment() {

  /**
   * Register a payment
   * @param {object} params Object containing params for register online payment method
   * @returns {boolean} False
   */
  function registerPayment(params) {
    var url = VuFind.path + '/AJAX/JSON?method=registerOnlinePayment';
    $.ajax({
      type: 'POST',
      url: url,
      data: params,
      dataType: 'json'
    })
      .done(function onRegisterPaymentDone() {
        // Clear account notification cache and reload current page without parameters
        VuFind.account.clearCache();
        location.href = window.location.href.split('?')[0];
      })
      .fail(function onRegisterPaymentFail() {
        // Reload current page without parameters
        location.href = window.location.href.split('?')[0];
      });

    return false;
  }

  var my = {
    registerPayment: registerPayment
  };

  return my;
})();
