import type {Metadata} from 'next';
import './globals.css';
export const metadata:Metadata={title:'Bilety · Decka Pelplin',description:'Wybierz swoje miejsce na meczu Decki Pelplin.'};
export default function Layout({children}:{children:React.ReactNode}){return <html lang="pl"><body>{children}</body></html>}
