/**
 * Created by remz on 7/10/2018.
 */
var app = angular.module("smApp", ['cgBusy', 'ui.bootstrap']);
app.run(function() {
   console.log('sm app run....')
});
app.filter('dataURLtoBlob', function() {
    return function(dataurl) {
        var arr = dataurl.split(','), mime = arr[0].match(/:(.*?);/)[1],
            bstr = atob(arr[1]), n = bstr.length, u8arr = new Uint8Array(n);
        while(n--){
            u8arr[n] = bstr.charCodeAt(n);
        }
        return new Blob([u8arr], {type:mime});
    }
});
app.filter("range", function() {
    return function(input, total) {
        total = parseInt(total);
        for (var i = 0; i < total; i++) {
            input.push(i);
        }
        return input;
    };
});
app.filter('start', function () {
    return function (input, start) {
        if (!input || !input.length) { return; }
        start = +start;
        return input.slice(start);
    };
});
app.directive('fileModel', ['$parse', function ($parse) {
    return {
        restrict: 'A',
        link: function(scope, element, attrs) {
            var model = $parse(attrs.fileModel);
            var modelSetter = model.assign;

            element.bind('change', function(){
                scope.$apply(function(){
                    modelSetter(scope, element[0].files[0]);
                });
            });
        }
    };
}]);
app.directive("datepicker", function () {
    return {
        restrict: "A",
        link: function (scope, el, attr) {
            var dateToday = new Date();
            el.datepicker({
                minDate: dateToday,
                dateFormat: 'yy-mm-dd'
            });
        }
    };
});
app.directive("submit", [function () {
    return {
        scope: {
            submit: "="
        },
        link: function (scope, element, attributes) {
            element.bind("submit", function (loadEvent) {
                return scope.submit(loadEvent);
            });
        }
    }
}]);
