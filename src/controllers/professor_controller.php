<?php

function professorStandingsController(): void {
    $user = requireProfessor();
    session_write_close();
    jsonSuccess(professorStandings($user['userId']));
}
