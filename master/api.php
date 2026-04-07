<?php
// Minimal API for job handling

$config = json_decode(file_get_contents("config.json"), true);
$jobs_file = "jobs.csv";

function read_jobs($file) {
    $rows = array_map('str_getcsv', file($file));
    $header = array_map('trim', array_shift($rows));
    $jobs = [];

    foreach ($rows as $row) {
        $jobs[] = array_combine($header, $row);
    }
    return $jobs;
}

function write_jobs($file, $jobs) {
    $f = fopen($file, 'w');
    fputcsv($f, array_keys($jobs[0]), ';');

    foreach ($jobs as $job) {
        fputcsv($f, $job, ';');
    }
    fclose($f);
}

$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";
$computer_id = $input["computer_id"] ?? "unknown";

$jobs = read_jobs($jobs_file);

function find_next_job(&$jobs) {
    foreach ($jobs as &$job) {
        if ($job["enabled"] === "TRUE" && $job["status"] === "queued") {
            return $job;
        }
    }
    return null;
}

function update_job(&$jobs, $job_id, $updates) {
    foreach ($jobs as &$job) {
        if ($job["job_id"] == $job_id) {
            foreach ($updates as $k => $v) {
                $job[$k] = $v;
            }
        }
    }
}

if ($action === "request_job") {
    $job = find_next_job($jobs);

    if (!$job) {
        echo json_encode([
            "status" => "ok",
            "action" => "sleep",
            "sleep_seconds" => $config["sleep_seconds"]
        ]);
        exit;
    }

    echo json_encode([
        "status" => "ok",
        "action" => "job",
        "job" => [
            "job_id" => (int)$job["job_id"],
            "expected_repo_id" => $config["repo_id"],
            "allow_version_override" => $config["debug_allow_version_override"],
            "task" => $job["preset"],
            "input_path" => $job["input_path"],
            "output_path" => $job["output_path"]
        ]
    ]);
    exit;
}

if ($action === "accept_job") {
    $job_id = $input["job_id"];

    update_job($jobs, $job_id, [
        "status" => "in_progress",
        "assigned_to" => $computer_id
    ]);

    write_jobs($jobs_file, $jobs);

    echo json_encode(["status" => "ok"]);
    exit;
}

if ($action === "finish_job") {
    $job_id = $input["job_id"];
    $result = $input["result"];

    if ($result === "done") {
        update_job($jobs, $job_id, [
            "status" => "done"
        ]);
    } else {
        update_job($jobs, $job_id, [
            "status" => "failed",
            "last_error" => $input["message"] ?? ""
        ]);
    }

    write_jobs($jobs_file, $jobs);

    echo json_encode([
        "status" => "ok",
        "action" => "sleep",
        "sleep_seconds" => $config["sleep_seconds"]
    ]);
}
