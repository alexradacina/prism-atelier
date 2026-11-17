export function money(value, exact = false) {
    if (value === null || value === undefined) return "—";
    return (
        "$" +
        Number(value).toLocaleString("en-US", {
            minimumFractionDigits: exact ? 9 : 2,
            maximumFractionDigits: exact ? 9 : 6,
        })
    );
}
export function date(value) {
    return new Date(value).toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}
