angular.module('smApp')

.controller('NewWorkoutController', function($scope, $http) {

    $scope.clients = CLIENTS;
    $scope.clientsBackUp = angular.copy(CLIENTS);
    $scope.exerciseOptions = EXERCISE_OPTIONS;
    $scope.exerciseSQoptions = EXERCISE_SQ_OPTIONS;
    $scope.clientExerciseSets = [];
    $scope.currentUser = CURRENT_USER;
    $scope.workoutMaxSet = 0;
    $scope.reader = {selectedClient:false};
    $scope.workoutTemplate = ROOT_URL + '/wp-content/themes/Divi-child/partials/new_workout.html';
    var urlApiClient = ROOT_URL + '/wp-json/v1';
    
    init();

    function init()
    {
        console.log('-----------INIT NewWorkoutController--------------');

        console.log('CLIENTS');
        console.log($scope.clients);
        console.log('CLIENTS');
        console.log(CURRENT_USER);
        $http.get(urlApiClient + '/hash').then(function(res)
        {
            $scope.workout = {
                days: [{name:'', seq:1, exercises:[generateNewExercise(res.data.hash)], clients:[]}]
            };

            selectDay($scope.workout.days[0]);
        });

        console.log('-----------END INIT--------------');
    }

    $scope.newWorkOutDay = function ()
    {
        $http.get(urlApiClient + '/hash').then(function(res) {

            var count = $scope.workout.days.length + 1;
            $scope.workout.days.push({seq:count, exercises:[generateNewExercise(res.data.hash)] , clients:[]});

            var countDays = $scope.workout.days.length;
            $scope.workout.selectedDay = $scope.workout.days[countDays - 1];

            $scope.selectedClient = "Add Client";

            selectDay($scope.workout.days[countDays - 1])

        });

    };

    $scope.newExercise = function() {

        $http.get(urlApiClient + '/hash').then(function(res)
        {
            $scope.workout.selectedDay.exercises.push(generateNewExercise(res.data.hash));
            optimizeClientExercises();
        });
    };

    $scope.onSelectDay = function(day)
    {
        selectDay(day);
    };

    $scope.onCopy = function()
    {
        var newCopy = angular.copy($scope.workout.selectedDay);


        $http.get(urlApiClient + '/hash').then(function(res)
        {
            console.log(newCopy);
            newCopy.exercises.forEach(function(ex) {
                ex.hash = res.data.hash;
            });

            newCopy.name = '';
            $scope.workout.days.push(newCopy);
            var countDays = $scope.workout.days.length;
            optimizeDays();
            selectDay($scope.workout.days[countDays - 1])

        });

    };

    $scope.onDelete = function(day)
    {
        if ($scope.workout.days.length > 1)
        {
            var idx = $scope.workout.days.indexOf(day);
            $scope.workout.days.splice(idx, 1);
            var countDays = $scope.workout.days.length;
            optimizeDays();
            selectDay($scope.workout.days[countDays - 1])
        }
    };

    $scope.onLeaveDay = function()
    {

        for (var i in $scope.workout.days)
        {
            var day = $scope.workout.days[i];

            if (day.seq == $scope.workout.selectedDay.seq)
            {
                $scope.workout.days[i] = angular.copy($scope.workout.selectedDay);

                for (var x in $scope.workout.days[i].clients)
                {
                    var client = $scope.workout.days[i].clients[x];

                    if ($scope.workout.selectedDay.selectedClient && client.ID == $scope.workout.selectedDay.selectedClient.id)
                    {
                        $scope.workout.days[i].clients[x] = $scope.workout.selectedDay.selectedClient;
                        break;
                    }
                }

                break;
            }
        }

    };

    $scope.onCopyExercise = function(exercise)
    {
        console.log(exercise);
        var newExercise = angular.copy(exercise);

        $http.get(urlApiClient + '/hash').then(function(res)
        {
            newExercise.hash = res.data.hash;
            $scope.workout.selectedDay.exercises.push(newExercise);
            optimizeClientExercises();
        });

    };

    $scope.onRemoveExercise = function(exercise)
    {

        if ($scope.workout.selectedDay.exercises.length > 1)
        {
            var idx = $scope.workout.selectedDay.exercises.indexOf(exercise);
            $scope.workout.selectedDay.exercises.splice(idx,1);
            console.log(exercise);
            console.log(idx);
        }

    };

    $scope.testWorkout = function()
    {
        console.log($scope.workout);
    };

    $scope.selectClient = function(client) {
        console.log(client);
        $scope.workout.selectedDay.selectedClient = client;
    };

    $scope.isActive = function(day)
    {

        if ($scope.workout.selectedDay.seq == day.seq) {
            return true;
        }

        return false;
    };

    $scope.isClientActive = function(client) {

        if ($scope.workout.selectedDay.selectedClient && $scope.workout.selectedDay.selectedClient.ID == client.ID) {
            return true;
        }

        return false;
    };

    $scope.onChangeDayName = function()
    {
        $scope.onLeaveDay();
    };

    $scope.sendForm = function() {

        var toSend = angular.copy($scope.workout);

        delete toSend.selectedDay;

        for(var i in toSend.days)
        {
            var day = toSend.days[i];

            delete day.selectedClient;
            for(var e in day.exercises)
            {
                var ex = day.exercises[e];
                delete ex.exerciseOptions;
                delete ex.exerciseSQoptions;
            }

            for (var x in day.clients)
            {
                var client  = day.clients[x];

                for (var m in client.exercises)
                {
                    var clientExercise = client.exercises[m];
                    delete clientExercise.exerciseOptions;
                    delete clientExercise.exerciseSQoptions;
                    delete clientExercise.selectedPart;
                    delete clientExercise.selectedSQ;
                }
            }
        }

        console.log(toSend);
        toSend.user_id = CURRENT_USER_ID;
        $('#idWorkoutForm').val(JSON.stringify(toSend));
        return true;
    };

    $scope.$watch('reader.selectedClient', function(val)
    {
        console.log('selectedClient');
        console.log(val);

        if (val)
        {
            var found = false;
            for (var i in $scope.clients)
            {
                var client = $scope.clients[i];

                if (client.ID == val)
                {

                    for (var x in $scope.workout.selectedDay.clients)
                    {
                        var xClient = $scope.workout.selectedDay.clients[x];

                        if(xClient.ID == val)
                        {
                            found = true;
                        }
                    }

                    if (!found) {
                        $scope.workout.selectedDay.clients.push(client);
                        $scope.selectClient(client);
                    }

                    break;
                }
            }
            optimizeClientExercises();
            optimizeSelectedClients();
        }

    }, true);

    $scope.$watch('workout.selectedDay.exercises', function(val){
        console.log('/* get the largest set in a selected day */');
        console.log(val);

        if (val) {
            findTheLargestSet();
            optimizeClientExercises();
        }

    }, true);

    function optimizeSelectedClients()
    {
        $scope.clients = angular.copy($scope.clientsBackUp);

        var listToDelete = [];

        for (var i = 0; i < $scope.workout.selectedDay.clients.length; i++) {
            listToDelete.push($scope.workout.selectedDay.clients[i].ID);
        }

        var lengthToDelete = listToDelete.length;

        for(var i = 0; i < $scope.clients.length; i++) {
            var obj = $scope.clients[i];

            if(listToDelete.indexOf(obj.ID) !== -1) {
                $scope.clients.splice(i, lengthToDelete);
            }
        }
    }

    function generateNewExercise(hash)
    {
        return {
            hash:hash,
            exerciseOptions: angular.copy($scope.exerciseOptions),
            exerciseSQoptions: angular.copy($scope.exerciseSQoptions)
            //assignment_sets: nAssignments
        };
    }

    function selectDay(day)
    {
        $scope.workout.selectedDay = angular.copy(day);
        //$scope.workout.selectedDay = day;
        optimizeSelectedClients();

    }

    function optimizeDays()
    {
        var count = 1;

        for(var i in $scope.workout.days)
        {
            var day = $scope.workout.days[i];

            $scope.workout.days[i].seq = count;
            count++;
        }

    }

    function optimizeClientExercises()
    {

        for (var i in $scope.workout.selectedDay.exercises)
        {
            var exercise = $scope.workout.selectedDay.exercises[i];
            var nAssignments = [];

            if (exercise.selectedSQ && exercise.selectedSQ.selectedRep)
            {

                for (var i=0; i<$scope.workoutMaxSet; i++) {
                    nAssignments.push({reps:exercise.selectedSQ.selectedRep, weight:''});
                }
            }

            exercise.assignment_sets = nAssignments;
        }

        for (var i in $scope.workout.selectedDay.clients)
        {
            var client = $scope.workout.selectedDay.clients[i];
            var mNewExercises = [];
            for (var m in $scope.workout.selectedDay.exercises)
            {
                var exercise = angular.copy($scope.workout.selectedDay.exercises[m]);

                for (var z in client.exercises) {

                    var zExer = client.exercises[z];

                    if (zExer.hash == exercise.hash)
                    {
                        exercise.assignment_sets = angular.copy(zExer.assignment_sets);

                    }
                }
                mNewExercises.push(exercise);
            }

            client.exercises = angular.copy(mNewExercises);
        }

        if($scope.$root.$$phase != '$apply' &&
            $scope.$root.$$phase != '$digest'
        ) {
            $scope.$apply();
        }

    }

    function findTheLargestSet()
    {
        /* get the max set */
        $scope.workoutMaxSet = 0;

        for (var i in $scope.workout.selectedDay.exercises)
        {
            var exercise = angular.copy($scope.workout.selectedDay.exercises[i]);
            var noSet = 0;
            $scope.clientExerciseSets[i] = 0;

            if (exercise.exer_sets) {
                noSet = parseInt(angular.copy(exercise.exer_sets));
                $scope.clientExerciseSets[i] = parseInt(angular.copy(exercise.exer_sets));
            }

            if (exercise.selectedSQ && exercise.selectedSQ.selectedSet) {
                noSet = exercise.selectedSQ.selectedSet;
                $scope.clientExerciseSets[i] = exercise.selectedSQ.selectedSet;
            }

            if (typeof $scope.workoutMaxSet == 'undefined') {
                $scope.workoutMaxSet = 0;

                if(noSet >= $scope.workoutMaxSet) {
                    $scope.workoutMaxSet = noSet;
                }

            } else if(noSet >= $scope.workoutMaxSet) {
                $scope.workoutMaxSet = noSet;
            }
        }
    }

    setTimeout(function() {
        $("#idSelectClient").change(function() {
            console.log($this.val());
        });
    }, 500);


    $("#idForm").submit(function (e)
    {

        e.preventDefault();

        delete $scope.workout.selectedDay;

        for(var i in $scope.workout.days)
        {
            var day = $scope.workout.days[i];

            delete day.selectedClient;
            for(var e in day.exercises)
            {
                var ex = day.exercises[e];
                delete ex.exerciseOptions;
                delete ex.exerciseSQoptions;
            }

            for (var x in day.clients)
            {
                var client  = day.clients[x];

                for (var m in client.exercises)
                {
                    var clientExercise = client.exercises[m];
                    delete clientExercise.exerciseOptions;
                    delete clientExercise.exerciseSQoptions;
                    delete clientExercise.selectedPart;
                    delete clientExercise.selectedSQ;
                }
            }
        }

        console.log($scope.workout);
        $scope.workout.user_id = CURRENT_USER_ID;
        $('#idWorkoutForm').val(JSON.stringify($scope.workout));
        return true;

    });
});