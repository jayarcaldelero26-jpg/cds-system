import { createContext, useContext } from 'react';

export const ReportDetailsContext = createContext(false);

export function useReportDetails() {
    return useContext(ReportDetailsContext);
}
