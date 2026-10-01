import '../css/app.css';
import './bootstrap';

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import AppRouter from './AppRouter';
import { MetaProvider } from './context/MetaContext';

createRoot(document.getElementById('root')).render(
    <StrictMode>
        <BrowserRouter>
            <MetaProvider>
                <AppRouter />
            </MetaProvider>
        </BrowserRouter>
    </StrictMode>,
);
