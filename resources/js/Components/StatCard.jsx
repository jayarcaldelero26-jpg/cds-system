// resources/js/Components/StatCard.jsx
export default function StatCard({ title, value, icon, color = 'bg-gradient-to-br from-green-600 to-green-800' }) {
    return (
        <div className={`relative overflow-hidden rounded-2xl p-5 text-white shadow-lg transition hover:scale-[1.02] ${color}`}>
            {/* Limpyo ug solid/sharp nga lingin sa taas-tuo */}
            <div className="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-white/15 pointer-events-none"></div>

            <div className="relative z-10 flex items-center justify-between">
                <span className="text-3xl">{icon}</span>
                <span className="text-2xl sm:text-3xl font-extrabold tracking-tight">{value}</span>
            </div>
            <div className="relative z-10 mt-4">
                <h4 className="text-xs font-bold uppercase tracking-wider opacity-90">{title}</h4>
            </div>
        </div>
    );
}
