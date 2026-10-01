import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import '../css/app.css';
import './bootstrap';
import { AuthProvider } from './contexts/AuthContext';
import { MetaProvider } from './context/MetaContext';
import AppRouter from './AppRouter';

createRoot(document.getElementById('root')).render(
    <StrictMode>
        <BrowserRouter>
            <AuthProvider>
                <MetaProvider>
                    <AppRouter />
                </MetaProvider>
            </AuthProvider>
        </BrowserRouter>
    </StrictMode>,
);
