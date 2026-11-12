// Written by scripts/github-contributions.mjs in the source repository.
// Do not edit by hand: the next deploy overwrites it.
//
// Reads the contribution calendar and rewrites contributions.json at the
// repository root — the file every page fetches after it loads. Fails soft:
// a bad day at the API leaves yesterday's number in place.
import { writeFileSync } from "node:fs";

const GITHUB_API = "https://api.github.com/graphql";
const PROFILE_QUERY = "query($login: String!) {\n  user(login: $login) {\n    createdAt\n    contributionsCollection {\n      contributionCalendar {\n        totalContributions\n        weeks {\n          contributionDays { date contributionCount }\n        }\n      }\n    }\n  }\n}";
const RANGE_QUERY = "query($login: String!, $from: DateTime!, $to: DateTime!) {\n  user(login: $login) {\n    contributionsCollection(from: $from, to: $to) {\n      contributionCalendar { totalContributions }\n    }\n  }\n}";

function yearRanges(createdAt, now = new Date()) {
  const start = new Date(createdAt);
  const end = new Date(now);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return [];
  if (start >= end) return [];

  const ranges = [];
  let from = start;
  while (from < end) {
    const yearEnd = new Date(
      Date.UTC(from.getUTCFullYear(), 11, 31, 23, 59, 59),
    );
    const to = yearEnd < end ? yearEnd : end;
    ranges.push({ from: from.toISOString(), to: to.toISOString() });
    from = new Date(Date.UTC(from.getUTCFullYear() + 1, 0, 1, 0, 0, 0));
  }
  return ranges;
}

function sumYears(totals) {
  return totals.reduce(
    (total, value) => total + (Number.isFinite(value) ? value : 0),
    0,
  );
}

function packCalendar(weeks) {
  const days = (weeks ?? []).flatMap((week) => week.contributionDays ?? []);
  if (days.length === 0) return null;

  return {
    /** The first day in the grid, `YYYY-MM-DD`. */
    start: days[0].date,
    counts: days.map((day) =>
      Number.isFinite(day.contributionCount) ? day.contributionCount : 0,
    ),
  };
}

function serialiseContributions(record) {
  const COUNTS = "@@COUNTS@@";
  const shaped = record.calendar
    ? { ...record, calendar: { ...record.calendar, counts: COUNTS } }
    : record;

  const json = JSON.stringify(shaped, null, 2).replace(
    `"${COUNTS}"`,
    record.calendar ? `[${record.calendar.counts.join(", ")}]` : "null",
  );
  return `${json}\n`;
}

async function graphql(query, variables, token, fetchImpl = fetch) {
  const response = await fetchImpl(GITHUB_API, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      "User-Agent": "cormeraie-fr",
    },
    body: JSON.stringify({ query, variables }),
  });

  if (!response.ok) {
    throw new Error(`GitHub GraphQL responded ${response.status}`);
  }

  const payload = await response.json();
  if (payload.errors?.length) {
    throw new Error(payload.errors.map((e) => e.message).join("; "));
  }
  if (!payload.data?.user) {
    throw new Error(`No such user: ${variables.login}`);
  }
  return payload.data;
}

const login = process.env.LOGIN;
const token = process.env.TOKEN;

if (!token) {
  console.warn("No token available; leaving contributions.json untouched.");
  process.exit(0);
}

try {
  const profile = await graphql(PROFILE_QUERY, { login }, token);
  const createdAt = profile.user.createdAt;
  const calendar = profile.user.contributionsCollection.contributionCalendar;
  const lastYear = calendar.totalContributions;

  const totals = [];
  for (const range of yearRanges(createdAt, new Date())) {
    const slice = await graphql(RANGE_QUERY, { login, ...range }, token);
    totals.push(
      slice.user.contributionsCollection.contributionCalendar.totalContributions,
    );
  }

  const record = {
    login,
    since: String(new Date(createdAt).getUTCFullYear()),
    createdAt,
    lastYear,
    allTime: sumYears(totals),
    updatedAt: new Date().toISOString(),
    calendar: packCalendar(calendar.weeks),
  };

  writeFileSync("contributions.json", serialiseContributions(record));
  console.log(
    `lastYear ${record.lastYear}, allTime ${record.allTime}, days ${record.calendar ? record.calendar.counts.length : 0}`,
  );
} catch (error) {
  console.warn(`Refresh failed (${error.message}); keeping the current file.`);
  process.exit(0);
}
