$(document).ready(function() {
  var windowSize = $(window).width();

  if (windowSize > 769) {
    $('#bli').click(function() {
      location.href = this.href;
    });
  }

  $(window).resize(function() {
    windowSize = $(window).width();
    if (windowSize < 769) {
      $('#bli').click(function() {
        return false;
      });
    }
  });
});
