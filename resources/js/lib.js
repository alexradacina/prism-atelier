export function toNanos(value) {
    const match = String(value).match(/^(-?)(\d+)(?:\.(\d{1,9}))?$/);
    if (!match) throw new TypeError("Invalid dollar amount");
    const amount =
        BigInt(match[2]) * 1_000_000_000n +
        BigInt((match[3] || "").padEnd(9, "0"));
    return match[1] ? -amount : amount;
}
export function money(value, exact = false) {
    if (value === null || value === undefined) return "—";
    const nanos = toNanos(value);
    const negative = nanos < 0n;
    const absolute = negative ? -nanos : nanos;
    const display = exact ? absolute : ((absolute + 500n) / 1000n) * 1000n;
    const whole = (display / 1_000_000_000n)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    const fraction = (display % 1_000_000_000n).toString().padStart(9, "0");
    const decimals = exact
        ? fraction
        : fraction.slice(0, 6).replace(/0+$/, "").padEnd(2, "0");
    return (negative && display !== 0n ? "−$" : "$") + whole + "." + decimals;
}
export function date(value) {
    return new Date(value).toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}
